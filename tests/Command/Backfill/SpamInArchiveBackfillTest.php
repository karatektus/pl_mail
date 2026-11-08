<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\SpamInArchiveBackfillTask;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The spam a "Mark as spam" filter archived on the server goes to Spam, and
 * to Junk on the server; nothing else in the archive moves.
 */
final class SpamInArchiveBackfillTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labels;
    private Account $account;
    private Mailbox $archive;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->labels     = $container->get(LabelResolver::class);

        // Never committed, so the suite leaves nothing behind.
        $this->connection->beginTransaction();

        $this->account = $this->seedAccount();
        $this->seedFolder('INBOX', MailboxSpecialUse::INBOX, LabelRole::Inbox);
        $this->archive = $this->seedFolder('Archive', MailboxSpecialUse::ARCHIVE, LabelRole::Archive);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testFilteredSpamInTheArchiveGoesToJunk(): void
    {
        $this->seedFolder('Junk', MailboxSpecialUse::JUNK, LabelRole::Spam);
        $spam = $this->seedArchived(spam: true);

        $this->runTask();

        self::assertSame([['move', 'Archive', 'Junk']], $this->serverJobsFor($spam), 'the copy in Archive was not moved to Junk');
        self::assertTrue($this->has($spam, LabelRole::Spam));
        self::assertFalse($this->has($spam, LabelRole::Archive), 'the spam is still in the Archive');
    }

    public function testArchivedMailThatIsNotSpamStaysPut(): void
    {
        $this->seedFolder('Junk', MailboxSpecialUse::JUNK, LabelRole::Spam);
        $ham = $this->seedArchived(spam: false);

        $this->runTask();

        self::assertSame([], $this->serverJobsFor($ham));
        self::assertTrue($this->has($ham, LabelRole::Archive));
    }

    /** Moving it would archive it again: with no Junk, it is left and counted. */
    public function testWithoutAJunkFolderTheSpamIsLeftAlone(): void
    {
        $spam = $this->seedArchived(spam: true);

        $this->runTask();

        self::assertSame([], $this->serverJobsFor($spam));
        self::assertTrue($this->has($spam, LabelRole::Archive));
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    private function runTask(): void
    {
        $task = self::getContainer()->get(SpamInArchiveBackfillTask::class);

        self::assertSame(0, $task->run(new SymfonyStyle(new ArrayInput([]), new BufferedOutput())));
    }

    /** Read from the table: the task clears the unit of work. */
    private function has(Message $message, LabelRole $role): bool
    {
        return 0 < (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message_label ml JOIN label l ON l.id = ml.label_id WHERE ml.message_id = ? AND l.role = ?',
            [$message->id, $role->value],
        );
    }

    /**
     * Each IMAP job queued for the message: action, source folder, destination.
     *
     * @return list<array{string, string|null, string|null}>
     */
    private function serverJobsFor(Message $message): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.export');
        $jobs      = [];

        foreach ($transport->getSent() as $envelope) {
            $job = $envelope->getMessage();

            if ($job instanceof ApplyImapFlagsMessage && true === isset($job->messageIds[(int) $message->id])) {
                $source = $this->em->find(Mailbox::class, $job->messageIds[(int) $message->id]);
                $jobs[] = [$job->action, $source?->fullPath, $job->destinationPath];
            }
        }

        return $jobs;
    }

    private function role(LabelRole $role): Label
    {
        return $this->labels->systemLabel($role, $this->account);
    }

    /** In the server's Archive folder, as the old filter left it. */
    private function seedArchived(bool $spam): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->archive;
        $message->imapUid        = random_int(1, 1_000_000);
        $message->subject        = $spam ? 'You have won' : 'Minutes';
        $message->fromAddress    = $spam ? 'spammer@example.test' : 'colleague@example.test';
        $message->receivedAt     = new \DateTimeImmutable('2026-11-07 09:00:00');
        $message->hasAttachments = false;
        $message->bodyHtml       = '<p>hello</p>';
        $message->messageId      = sprintf('spam-in-archive-%s@example.test', uniqid('', true));
        $message->addLabel($this->role(LabelRole::Archive));

        if (true === $spam) {
            $message->addLabel($this->role(LabelRole::Spam));
        }

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seedFolder(string $name, MailboxSpecialUse $specialUse, LabelRole $role): Mailbox
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->specialUse    = $specialUse;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);
        $this->em->flush();

        $this->labels->bindMailbox($this->role($role), $mailbox);
        $this->em->flush();

        return $mailbox;
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'spam-in-archive-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Spam';
        $user->nameLast  = 'Archive';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'spam-in-archive-fixture-' . uniqid('', true) . '@example.test';

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
