<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MessageThread::$binned, which the database keeps (#223).
 *
 * The lists stopped working out "in the bin" from the messages and read this
 * column instead, so a wrong value is a conversation missing from the Inbox
 * or a deleted one still in it. These drive the ways the answer changes
 * through the ORM, as the app writes them, and read the column back from the
 * database, which is where the triggers put it. The rule itself is #268's:
 * Trash on the thread and no message outside Trash.
 */
final class ThreadBinnedTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labels;
    private ThreadLabelSynchronizer $threadLabels;

    private Account $account;
    private Label $inbox;
    private Label $trash;

    protected function setUp(): void
    {
        self::bootKernel();

        $container          = self::getContainer();
        $this->em           = $container->get(EntityManagerInterface::class);
        $this->connection   = $container->get(Connection::class);
        $this->labels       = $container->get(LabelResolver::class);
        $this->threadLabels = $container->get(ThreadLabelSynchronizer::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'binned-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Bin';
        $user->nameLast  = 'Test';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $this->account           = new Account();
        $this->account->usr      = $user;
        $this->account->email    = 'me@example.test';
        $this->account->username = uniqid('binned-', true);
        $this->account->imapHost = 'imap.example.test';
        $this->account->authType = AuthType::Password->value;
        $this->account->isActive = true;
        $this->em->persist($this->account);
        $this->em->flush();

        $this->inbox = $this->labels->systemLabel(LabelRole::Inbox, $this->account);
        $this->trash = $this->labels->systemLabel(LabelRole::Trash, $this->account);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAConversationIsInTheBinOnceAllOfItIs(): void
    {
        $thread = $this->thread(2);
        [$first, $second] = $this->messagesOf($thread);

        $this->moveToTrash($first);
        self::assertFalse($this->binned($thread), 'one message deleted, the other still in the Inbox');

        $this->moveToTrash($second);
        self::assertTrue($this->binned($thread));

        $this->restore($second);
        self::assertFalse($this->binned($thread), 'restored: a live message again');
    }

    /** #268: a reply into a conversation in the bin brings it back. */
    public function testAReplyArrivingTakesItOutOfTheBin(): void
    {
        $thread = $this->thread(1);
        $this->moveToTrash($this->messagesOf($thread)[0]);
        self::assertTrue($this->binned($thread));

        $this->message($thread, [$this->inbox]);
        $this->threadLabels->sync($thread);
        $this->em->flush();

        self::assertFalse($this->binned($thread));
    }

    /** The last live message going, deleted or moved away, leaves the rest in the bin. */
    public function testTheLastLiveMessageLeavingPutsTheRestInTheBin(): void
    {
        $thread = $this->thread(2);
        [$deleted, $live] = $this->messagesOf($thread);
        $this->moveToTrash($deleted);
        self::assertFalse($this->binned($thread));

        $other        = $this->thread(1);
        $live->thread = $other;
        $this->em->flush();

        self::assertTrue($this->binned($thread), 'its live message moved to another conversation');
        self::assertFalse($this->binned($other));
    }

    public function testDeletingTheLiveMessagePutsTheRestInTheBin(): void
    {
        $thread = $this->thread(2);
        [$deleted, $live] = $this->messagesOf($thread);
        $this->moveToTrash($deleted);

        $this->em->remove($live);
        $this->em->flush();

        self::assertTrue($this->binned($thread));
    }

    /** What the lists see: a deleted conversation is out of the Inbox, a live one in it. */
    public function testTheInboxReadsIt(): void
    {
        $live    = $this->thread(1);
        $deleted = $this->thread(1);
        $this->moveToTrash($this->messagesOf($deleted)[0]);

        /** @var MessageThreadRepository $threads */
        $threads = $this->em->getRepository(MessageThread::class);
        $listed  = array_map(
            static fn (MessageThread $thread): ?int => $thread->id,
            $threads->findForAccountInbox($this->account),
        );

        self::assertContains($live->id, $listed);
        self::assertNotContains($deleted->id, $listed);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function binned(MessageThread $thread): bool
    {
        return true === (bool) $this->connection->fetchOne('SELECT binned FROM message_thread WHERE id = ?', [$thread->id]);
    }

    /** What trashing does to labels: Inbox off, Trash on, the thread's union re-derived. */
    private function moveToTrash(Message $message): void
    {
        $message->removeLabel($this->inbox);
        $message->addLabel($this->trash);
        $this->settle($message);
    }

    private function restore(Message $message): void
    {
        $message->removeLabel($this->trash);
        $message->addLabel($this->inbox);
        $this->settle($message);
    }

    private function settle(Message $message): void
    {
        $thread = $message->thread;
        self::assertNotNull($thread);
        $this->threadLabels->sync($thread);
        $this->em->flush();
    }

    private function thread(int $messages): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Rechnung';
        $thread->normalizedSubject = 'rechnung';
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $this->em->persist($thread);

        for ($i = 0; $i < $messages; ++$i) {
            $this->message($thread, [$this->inbox]);
        }

        $this->threadLabels->sync($thread);
        $this->em->flush();

        return $thread;
    }

    /**
     * @param list<Label> $labels
     */
    private function message(MessageThread $thread, array $labels): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Rechnung';
        $message->messageId      = uniqid('', true) . '@example.test';
        $message->receivedAt     = new DateTimeImmutable();
        $message->hasAttachments = false;
        $message->flags          = [];

        foreach ($labels as $label) {
            $message->addLabel($label);
        }

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /** @return list<Message> */
    private function messagesOf(MessageThread $thread): array
    {
        return $this->em->getRepository(Message::class)->findBy(['thread' => $thread], ['id' => 'ASC']);
    }
}
