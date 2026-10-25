<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\RawMessageStorage;
use App\Domain\Helper\SealedMessageStorage;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\MessageEraser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * How a message row leaves the database, whoever decided it should.
 *
 * The syncers call this when the server no longer has a message, the composer
 * when a draft is discarded, and MessagePurger when the user deletes for good.
 * What the tests below hold it to is what is left behind: in the unit of work,
 * in the thread, and in the JMAP change log.
 *
 * The first is the one that reached production (#299). An erase left the
 * message's parts managed, the next flush in the same unit of work found them
 * pointing at a message Doctrine no longer knew, and the sync failed.
 */
final class MessageEraserTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageEraser $eraser;
    private LabelResolver $labelResolver;

    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->eraser        = $container->get(MessageEraser::class);
        $this->labelResolver = $container->get(LabelResolver::class);

        $this->connection->beginTransaction();

        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The production failure, in the order a sync runs it: a message that has
     * a part is found, erased and flushed, and then something else is written
     * in the same unit of work. GmailApiSyncer flushes its history run, and
     * SyncAccountMessageHandler then flushes the account's sync time.
     *
     * Before the fix the second flush threw "a new entity was found through the
     * relationship MessagePart#message".
     */
    public function testAFlushAfterTheEraseStillGoesThrough(): void
    {
        $thread    = $this->thread(2);
        $messageId = $thread->messages->first()->id;
        $partId    = $this->attachPart($thread->messages->first())->id;

        // Found again from nothing, as a sync finds it: nothing of it loaded
        // until the erase loads it.
        $this->em->clear();
        $message = $this->em->find(Message::class, $messageId);

        $this->eraser->erase($message);
        $this->em->flush();

        $this->em->find(Account::class, $this->account->id)->recordSyncSuccess();
        $this->em->flush();

        self::assertNull($this->em->find(Message::class, $messageId));
        self::assertFalse(
            $this->connection->fetchOne('SELECT 1 FROM message_part WHERE id = ?', [$partId]),
            'the part outlived its message',
        );
    }

    /**
     * A conversation that lost its last message goes with it, and JMAP clients
     * are told: an id they hold that no longer resolves is a conversation they
     * show and can never open.
     */
    public function testAThreadEmptiedByTheEraseIsRemovedAndAnnounced(): void
    {
        $thread   = $this->thread(1);
        $threadId = $thread->id;

        $this->eraser->erase($thread->messages->first());
        $this->em->flush();

        self::assertNull($this->em->find(MessageThread::class, $threadId));
        self::assertContains('Thread:destroyed:' . $threadId, $this->changeLog());
    }

    /**
     * One that still has messages stays, counted from what is left and wearing
     * only the labels those messages carry.
     */
    public function testAThreadThatKeepsMessagesIsRecountedAndRelabelled(): void
    {
        $thread = $this->thread(2);
        $trash  = $this->labelResolver->systemLabel(LabelRole::Trash, $this->account);
        $victim = $thread->messages->last();

        $victim->seenAt = null;
        $victim->addLabel($trash);
        self::getContainer()->get(ThreadLabelSynchronizer::class)->sync($thread);
        $this->em->flush();

        self::assertTrue($thread->hasLabel($trash), 'fixture: the thread wears the label first');

        $this->eraser->erase($victim);
        $this->em->flush();

        self::assertNotNull($this->em->find(MessageThread::class, $thread->id));
        self::assertSame(1, $thread->messageCount);
        self::assertSame(0, $thread->unreadCount);
        self::assertFalse($thread->hasLabel($trash), 'the thread kept a label only the erased message had');
        self::assertNotContains('Thread:destroyed:' . $thread->id, $this->changeLog());
    }

    /**
     * A file that will not go must not keep the row. The row is what anyone
     * can see; a leftover file is a cleanup problem.
     */
    public function testAFileThatWillNotDeleteDoesNotKeepTheRow(): void
    {
        $thread    = $this->thread(1);
        $message   = $thread->messages->first();
        $messageId = $message->id;

        $this->attachPart($message);
        $message->rawPath = 'var/raw/eraser-test.eml';

        $attachments = $this->createStub(AttachmentStorageHelper::class);
        $attachments->method('delete')->willThrowException(new \RuntimeException('Read-only file system'));

        $raw = $this->createStub(RawMessageStorage::class);
        $raw->method('delete')->willThrowException(new \RuntimeException('Read-only file system'));

        $eraser = new MessageEraser(
            $this->em,
            self::getContainer()->get(MailChangeRecorder::class),
            self::getContainer()->get(ThreadLabelSynchronizer::class),
            $attachments,
            $raw,
            self::getContainer()->get(SealedMessageStorage::class),
            new NullLogger(),
        );

        $eraser->erase($message);
        $this->em->flush();

        self::assertNull($this->em->find(Message::class, $messageId));
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /** @return list<string> "objectType:changeType:entityId" for this account */
    private function changeLog(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT object_type, change_type, entity_id FROM jmap_change_log
             WHERE account_id = ?
             ORDER BY sequence',
            [$this->account->id],
        );

        return array_map(
            static fn (array $row): string => sprintf(
                '%s:%s:%s',
                $row['object_type'],
                $row['change_type'],
                $row['entity_id'],
            ),
            $rows,
        );
    }

    private function attachPart(Message $message): MessagePart
    {
        $part              = new MessagePart();
        $part->filename    = 'note.txt';
        $part->contentType = 'text/plain';
        $part->disposition = 'attachment';
        $part->isInline    = false;
        $part->storagePath = 'var/attachments/eraser-test/note.txt';
        $part->size        = 16;
        $message->addMessagePart($part);
        $this->em->persist($part);
        $this->em->flush();

        return $part;
    }

    private function thread(int $messages): MessageThread
    {
        $inbox                     = $this->labelResolver->systemLabel(LabelRole::Inbox, $this->account);
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Eraser fixture';
        $thread->normalizedSubject = 'eraser fixture';
        $thread->lastMessageAt     = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->unreadCount       = 0;
        $thread->messageCount      = $messages;
        $this->em->persist($thread);

        for ($i = 0; $i < $messages; ++$i) {
            $message                 = new Message();
            $message->account        = $this->account;
            $message->subject        = sprintf('Eraser fixture %d', $i);
            $message->fromAddress    = 'sender@example.test';
            $message->receivedAt     = new \DateTimeImmutable(sprintf('-%d minutes', 60 - $i));
            $message->seenAt         = new \DateTimeImmutable('-1 hour');
            $message->hasAttachments = false;
            $message->messageId      = sprintf('<eraser-%s-%d@example.test>', uniqid('', true), $i);

            $message->addLabel($inbox);
            $thread->addMessage($message);

            $this->em->persist($message);
        }

        $this->em->flush();

        return $thread;
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'eraser-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Message';
        $user->nameLast  = 'Eraser';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'eraser-fixture-' . uniqid('', true) . '@example.test';

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = $email;
        $account->username       = $email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
