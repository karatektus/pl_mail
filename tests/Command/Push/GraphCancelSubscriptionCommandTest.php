<?php

declare(strict_types=1);

namespace App\Tests\Command\Push;

use App\Command\Push\GraphCancelSubscriptionCommand;
use App\Domain\Enum\Account\AuthType;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Service\Mail\GraphApiClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The way out of an orphaned Graph subscription.
 *
 * Run by an admin who has "GraphNotification: unknown subscription" in front of
 * them and wants it to stop before the three days are up. Everything asserted
 * here is a wrong invocation, and deliberately so: the successful path needs
 * Microsoft, and what actually costs someone their afternoon is a command that
 * takes a mistyped account id and says nothing useful about it.
 */
final class GraphCancelSubscriptionCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testBothOptionsAreRequired(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--account' => '1']));
        self::assertStringContainsString('required', $tester->getDisplay());
    }

    /** A mistyped id must say so rather than fail somewhere inside Graph. */
    public function testAnUnknownAccountIsReportedAsSuch(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([
            '--account'      => '99999999',
            '--subscription' => 'sub-orphan',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No account with id 99999999', $tester->getDisplay());
    }

    /**
     * Graph subscriptions are a Microsoft thing. Handing this a Gmail account
     * would otherwise send a DELETE with a Google token and report whatever
     * Microsoft said about it, which explains nothing.
     */
    public function testANonMicrosoftAccountIsRefusedBeforeAnyCallIsMade(): void
    {
        $account = $this->seedAccount('google');

        $tester = $this->tester();

        $status = $tester->execute([
            '--account'      => (string) $account->id,
            '--subscription' => 'sub-orphan',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('not a Microsoft account', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $container = self::getContainer();

        return new CommandTester(new GraphCancelSubscriptionCommand(
            $container->get(AccountRepository::class),
            $container->get(GraphApiClient::class),
        ));
    }

    private function seedAccount(string $provider): Account
    {
        $user            = new User();
        $user->email     = 'cancel-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Cancel';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);

        $account                  = new Account();
        $account->usr             = $user;
        $account->email           = $user->email;
        $account->name            = 'Fixture';
        $account->username        = $user->email;
        $account->isActive        = true;
        $account->imapHost        = 'imap.example.test';
        $account->imapPort        = 993;
        $account->imapEncryption  = 'ssl';
        $account->authType        = AuthType::OAuth2->value;
        $account->oauthProvider   = $provider;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
