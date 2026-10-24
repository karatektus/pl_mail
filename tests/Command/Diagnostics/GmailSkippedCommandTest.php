<?php

declare(strict_types=1);

namespace App\Tests\Command\Diagnostics;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\GmailSkipReason;
use App\Entity\Mail\Account;
use App\Entity\Mail\GmailSkippedMessage;
use App\Entity\User\User;
use App\Repository\Mail\GmailSkippedMessageRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:gmail:skipped`: the difference between Gmail's count and plMail's, put
 * into words.
 *
 * What is pinned is that each kind of declined message is named as what it
 * is — the whole use of the command is reading that table and deciding whether
 * a kind should have been stored after all.
 */
final class GmailSkippedCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'skipped-command-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Gmail';
        $user->nameLast  = 'Skipped';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                = new Account();
        $account->usr           = $user;
        $account->name          = 'Gmail fixture';
        $account->email         = 'me@gmail.test';
        $account->username      = uniqid('gmail-', true);
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = 'google';
        $account->isActive      = true;
        $account->importTotal   = 12;
        $this->em->persist($account);
        $this->em->flush();

        $this->account = $account;
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEachKindOfSkippedMessageIsNamedAndCounted(): void
    {
        $this->skip('c1', GmailSkipReason::Unattributable, ['CHAT']);
        $this->skip('d1', GmailSkipReason::Unattributable, ['DRAFT'], ['to' => 'rike@elsewhere.test']);
        $this->skip('d2', GmailSkipReason::Unattributable, ['DRAFT']);
        $this->skip('s1', GmailSkipReason::Unattributable, ['SENT'], ['from' => 'Work <me@work.test>', 'to' => 'x@elsewhere.test']);
        $this->skip('s2', GmailSkipReason::Unattributable, ['SENT'], ['from' => 'me@work.test', 'to' => 'y@elsewhere.test']);
        $this->skip('f1', GmailSkipReason::Unattributable, ['INBOX'], ['to' => 'old@isp.test', 'delivered-to' => 'old@isp.test']);
        $this->skip('n1', GmailSkipReason::Unattributable, ['INBOX'], ['from' => 'list@elsewhere.test']);
        $this->skip('g1', GmailSkipReason::Unfetchable);
        $this->em->flush();

        $output = $this->show((string) $this->account->id);

        self::assertMatchesRegularExpression('/A draft\s+2\b/', $output);
        self::assertMatchesRegularExpression('/A chat\s+1\b/', $output);
        self::assertMatchesRegularExpression("/Sent from an address that is not this account's\\s+2\\b/", $output);
        self::assertMatchesRegularExpression('/Delivered to another address\s+1\b/', $output);
        self::assertMatchesRegularExpression('/No recipient headers at all\s+1\b/', $output);
        self::assertMatchesRegularExpression('/Gmail lists it and could not return it\s+1\b/', $output);

        // The address a "send mail as" identity writes from, once, with both.
        self::assertMatchesRegularExpression('/me@work\.test\s+2\b/', $output);
        self::assertMatchesRegularExpression('/old@isp\.test\s+1\b/', $output);

        self::assertStringNotContainsString('rike@elsewhere.test', $output, 'the messages themselves only with --list');
        self::assertStringContainsString('rike@elsewhere.test', $this->show((string) $this->account->id, ['--list' => '20']));
    }

    public function testWithoutAnAccountThereIsOneLinePerGmailAccount(): void
    {
        $this->skip('d1', GmailSkipReason::Unattributable, ['DRAFT']);
        $this->em->flush();

        $output = $this->show(null);

        self::assertStringContainsString('me@gmail.test', $output);
        self::assertMatchesRegularExpression('/me@gmail\.test\s+0\s+1\s+12\s+importing/', $output);
    }

    public function testForgettingClearsTheRecordForThatAccount(): void
    {
        $this->skip('d1', GmailSkipReason::Unattributable, ['DRAFT']);
        $this->skip('d2', GmailSkipReason::Unattributable, ['DRAFT']);
        $this->em->flush();

        $output = $this->show('me@gmail.test', ['--forget' => true]);

        self::assertStringContainsString('Forgot 2 skipped message(s)', $output);
        self::assertSame(0, self::getContainer()->get(GmailSkippedMessageRepository::class)->countForAccount($this->account));
    }

    /**
     * @param list<string>          $labels
     * @param array<string, string> $headers
     */
    private function skip(string $gmailId, GmailSkipReason $reason, array $labels = [], array $headers = []): void
    {
        $this->em->persist(new GmailSkippedMessage($this->account, $gmailId, $reason, $labels, $headers));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function show(?string $account, array $options = []): string
    {
        $tester = new CommandTester(new Application(self::$kernel)->find('app:gmail:skipped'));

        $tester->execute((null === $account ? [] : ['account' => $account]) + $options, ['decorated' => false]);

        return $tester->getDisplay();
    }
}
