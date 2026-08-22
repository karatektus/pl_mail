<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\PurgeRemoteMessagesMessage;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MessagePurger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The only operation in plMail that destroys something.
 *
 * Everything else is a label change wearing a verb: archive removes Inbox,
 * trash adds Trash, and each is undone by putting the label back. This one
 * cannot be undone, which is why the assertions below are about what is left
 * behind rather than about what the user sees.
 *
 * The one that would be easy to get wrong, and is the reason this class exists
 * in the shape it does: the job that tells the provider has to be dispatched
 * with the message's ADDRESS on that provider, not with its local row id. Every
 * other propagation names rows and lets the handler look them up — which is
 * fine while the row survives the operation. Here it does not, so a handler
 * that looked it up would find nothing, return quietly, and leave the mail on
 * the server to reappear at the next sync as a message the user had explicitly
 * destroyed.
 */
final class MessagePurgerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessagePurger $purger;
    private LabelResolver $labelResolver;

    private Account $account;
    private Mailbox $inboxMailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->purger        = $container->get(MessagePurger::class);
        $this->labelResolver = $container->get(LabelResolver::class);

        $this->connection->beginTransaction();

        $this->account      = $this->seedAccount();
        $this->inboxMailbox = $this->seedMailbox();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testThePurgedMessageIsGoneFromTheDatabase(): void
    {
        $thread = $this->thread(2);
        $victim = $thread->messages->first();
        $id     = $victim->id;

        $this->purger->purge([$victim]);

        self::assertNull($this->em->getRepository(Message::class)->find($id));
    }

    /**
     * The address travels with the job, and this is the assertion the whole
     * design turns on.
     *
     * Dispatching the local row id — as every other propagation does — would
     * leave the worker with nothing to look up, because the row is deleted in
     * the same call. The mail would stay on the server and come back.
     */
    public function testTheProviderJobCarriesTheAddressRatherThanTheRowId(): void
    {
        $thread  = $this->thread(1);
        $message = $thread->messages->first();

        $this->purger->purge([$message]);

        $jobs = $this->dispatched();

        self::assertCount(1, $jobs, 'exactly one purge job, for the one account');
        self::assertSame(
            ['INBOX' => [2000]],
            $jobs[0]->imapUids,
            'the folder and UID must be in the envelope, not looked up later',
        );
    }

    /**
     * A message that never reached a server has no address on one, so nothing
     * is announced. Without this, discarding a local-only row would queue a job
     * that connects to IMAP to delete nothing.
     */
    public function testAMessageThatNeverReachedAServerAnnouncesNothing(): void
    {
        $thread          = $this->thread(1);
        $message         = $thread->messages->first();
        $message->imapUid = null;
        $message->mailbox = null;
        $this->em->flush();

        $this->purger->purge([$message]);

        self::assertCount(0, $this->dispatched());
    }

    /**
     * The bytes, not just the rows.
     *
     * Leaving the file behind makes the deletion a lie in the way that matters
     * most for a mail client: the message the user destroyed would still be
     * readable on the disk.
     */
    public function testTheAttachmentFileGoesWithTheMessage(): void
    {
        $thread  = $this->thread(1);
        $message = $thread->messages->first();

        // The real shape of a stored path. AttachmentStorageHelper::delete()
        // refuses anything not under the configured attachments directory —
        // which is the guard that stops a crafted storagePath deleting
        // arbitrary files — so a fixture with a made-up prefix would be
        // silently ignored and this test would pass while deleting nothing.
        $relative = 'var/attachments/purge-test/' . uniqid('', true) . '.txt';
        $absolute = self::getContainer()->getParameter('kernel.project_dir') . '/' . $relative;

        @mkdir(dirname($absolute), 0o775, true);
        file_put_contents($absolute, 'attachment bytes');

        $part              = new MessagePart();
        $part->message     = $message;
        $part->filename    = 'note.txt';
        $part->contentType = 'text/plain';
        $part->disposition = 'attachment';
        $part->isInline    = false;
        $part->storagePath = $relative;
        $part->size        = 16;
        $message->addMessagePart($part);
        $this->em->persist($part);
        $this->em->flush();

        self::assertFileExists($absolute);

        $this->purger->purge([$message]);

        self::assertFileDoesNotExist($absolute, 'the attachment outlived the message');
    }

    /**
     * A conversation that lost every message goes with them.
     *
     * Left behind it is a row in the list with a subject, no participants and a
     * count of zero — a shape nothing else in the app knows how to render.
     */
    public function testAThreadEmptiedByThePurgeIsRemovedToo(): void
    {
        $thread   = $this->thread(1);
        $threadId = $thread->id;

        $this->purger->purge([$thread->messages->first()]);

        self::assertNull($this->em->getRepository(MessageThread::class)->find($threadId));
    }

    /**
     * And one that still has messages keeps its count honest.
     *
     * Recounted from the association rather than decremented — the rule
     * DraftPersister already states, because a stored counter adjusted by
     * arithmetic drifts and it is what the thread header renders.
     */
    public function testAThreadThatKeepsMessagesKeepsAnHonestCount(): void
    {
        $thread = $this->thread(3);

        $this->purger->purge([$thread->messages->first()]);

        self::assertSame(2, $thread->messageCount);
        self::assertCount(2, $thread->messages);
    }

    public function testPurgingNothingDoesNothing(): void
    {
        self::assertSame(0, $this->purger->purge([]));
        self::assertCount(0, $this->dispatched());
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /** @return list<PurgeRemoteMessagesMessage> */
    private function dispatched(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.export');

        $found = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof PurgeRemoteMessagesMessage) {
                $found[] = $message;
            }
        }

        return $found;
    }

    private function thread(int $messages): MessageThread
    {
        $inbox                     = $this->labelResolver->systemLabel(LabelRole::Inbox, $this->account);
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Purge fixture';
        $thread->normalizedSubject = 'purge fixture';
        $thread->lastMessageAt     = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->unreadCount       = 0;
        $thread->messageCount      = $messages;
        $this->em->persist($thread);

        for ($i = 0; $i < $messages; ++$i) {
            $message                 = new Message();
            $message->account        = $this->account;
            $message->subject        = sprintf('Purge fixture %d', $i);
            $message->fromAddress    = 'sender@example.test';
            $message->receivedAt     = new \DateTimeImmutable('-1 hour');
            $message->hasAttachments = false;
            $message->messageId      = sprintf('<purge-%s-%d@example.test>', uniqid('', true), $i);
            // A mailbox and a UID, because a message with neither has no
            // address at a provider and the dispatch assertions would then be
            // testing the fixture rather than the service.
            $message->mailbox = $this->inboxMailbox;
            $message->imapUid = 2000 + $i;

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
        $user->email     = 'purger-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Message';
        $user->nameLast  = 'Purger';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'purger-fixture-' . uniqid('', true) . '@example.test';

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

    private function seedMailbox(string $name = 'INBOX'): Mailbox
    {
        $mailbox           = new Mailbox();
        $mailbox->account  = $this->account;
        $mailbox->name     = $name;
        $mailbox->fullPath = $name;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
