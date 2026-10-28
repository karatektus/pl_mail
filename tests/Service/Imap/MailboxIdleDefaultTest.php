<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\ImapConnector;
use App\Service\Imap\MailboxSyncer;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MessageEraser;
use App\Tests\Infrastructure\ImapClient\FakeImapServer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which folders a newly discovered account is watched on (#202).
 *
 * The inbox, and nothing else. Junk used to be watched too, which held one
 * IDLE connection per account for mail nobody waits on; the folders dialog is
 * where somebody who wants it switches it back on. The presence check matters
 * as much as the absence: a syncer that watched nothing would pass the Junk
 * assertion as well.
 */
final class MailboxIdleDefaultTest extends KernelTestCase
{
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

    public function testOnlyTheInboxIsWatchedWhenFoldersAreFirstFound(): void
    {
        $container = self::getContainer();

        new FakeImapServer($container->get(ImapLoop::class), [
            'INBOX'   => ['\\HasNoChildren'],
            'Junk'    => ['\\HasNoChildren', '\\Junk'],
            'Sent'    => ['\\HasNoChildren', '\\Sent'],
            'Archive' => ['\\HasNoChildren', '\\Archive'],
        ], '/')->pointAt($this->account);

        new MailboxSyncer(
            $container->get(MailboxRepository::class),
            $this->em,
            $container->get(ImapConnector::class),
            $container->get(LabelResolver::class),
            $container->get(MessageRepository::class),
            $container->get(MessageEraser::class),
            new NullLogger(),
        )->syncForAccount($this->account);

        $watched = [];

        foreach ($this->em->getRepository(Mailbox::class)->findBy(['account' => $this->account]) as $mailbox) {
            if ($mailbox->isIdleEnabled) {
                $watched[] = $mailbox->fullPath;
            }
        }

        self::assertSame(['INBOX'], $watched);
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'idle-default-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Idle';
        $user->nameLast = 'Default';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Idle Default Fixture';
        $account->username = 'idle-default@example.test';
        $account->imapHost = '127.0.0.1';
        $account->imapPort = 1143;
        $account->imapEncryption = 'starttls';
        $account->smtpHost = '127.0.0.1';
        $account->smtpPort = 1025;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }
}
