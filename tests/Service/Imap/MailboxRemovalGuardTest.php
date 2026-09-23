<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MailboxSyncer;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MessageEraser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One folder listing never deletes a folder, because deleting the folder row
 * deletes its mail (message.mailbox_id cascades).
 *
 * The syncer used to remove every stored folder a LIST did not name. An empty
 * answer, a server answering with half its tree, or a rename in another client
 * each wiped the folders' messages in one poll.
 */
final class MailboxRemovalGuardTest extends KernelTestCase
{
    private const array TREE = ['INBOX', 'INBOX.Sent', 'INBOX.Trash', 'INBOX.Work', 'INBOX.Family', 'INBOX.Receipts'];

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

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

    public function testAnEmptyListingRemovesAndMarksNothing(): void
    {
        $this->sync(self::TREE);
        $this->seedMessage('INBOX.Work');

        $result = $this->sync([]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(0, $result['missing']);
        self::assertCount(count(self::TREE), $this->mailboxes());
        self::assertSame(1, $this->messagesIn('INBOX.Work'));
    }

    public function testAPartialListingRemovesNothing(): void
    {
        $this->sync(self::TREE);
        $this->seedMessage('INBOX.Work');

        // Most of the tree gone at once is refused outright, however often it
        // is repeated.
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->sync(['INBOX', 'INBOX.Sent'], new \DateTimeImmutable('+' . (2 * $i) . ' days'));

            self::assertSame(0, $result['deleted']);
        }

        self::assertCount(count(self::TREE), $this->mailboxes());
        self::assertSame(1, $this->messagesIn('INBOX.Work'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param list<string> $paths
     *
     * @return array{created: int, updated: int, deleted: int, renamed: int, missing: int}
     */
    private function sync(array $paths, ?\DateTimeImmutable $now = null): array
    {
        $container = self::getContainer();

        $factory = self::createStub(ImapConnectionFactory::class);
        $factory->method('connect')->willReturn(new FakeListingClient(array_fill_keys($paths, [])));

        $syncer = new MailboxSyncer(
            $container->get(MailboxRepository::class),
            $this->em,
            $factory,
            $container->get(LabelResolver::class),
            $container->get(MessageRepository::class),
            $container->get(MessageEraser::class),
            new NullLogger(),
        );

        return $syncer->syncForAccount($this->account, $now);
    }

    /**
     * @return list<Mailbox>
     */
    private function mailboxes(): array
    {
        return $this->em->getRepository(Mailbox::class)->findBy(['account' => $this->account]);
    }

    private function messagesIn(string $fullPath): int
    {
        $mailbox = $this->em->getRepository(Mailbox::class)->findOneBy([
            'account'  => $this->account,
            'fullPath' => $fullPath,
        ]);

        return $this->em->getRepository(Message::class)->count(['mailbox' => $mailbox]);
    }

    private function seedMessage(string $fullPath): void
    {
        $mailbox = $this->em->getRepository(Mailbox::class)->findOneBy([
            'account'  => $this->account,
            'fullPath' => $fullPath,
        ]);

        $message = new Message();
        $message->account = $this->account;
        $message->mailbox = $mailbox;
        $message->imapUid = 1;
        $message->messageId = 'guard-' . uniqid('', true) . '@example.test';
        $message->subject = 'Keep me';
        $message->hasAttachments = false;
        $this->em->persist($message);
        $this->em->flush();
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'mailbox-guard-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Mailbox';
        $user->nameLast = 'Guard';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Mailbox Guard Fixture';
        $account->username = 'mailbox-guard-fixture@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }
}
