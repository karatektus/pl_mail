<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Maintenance\DataResetter;
use App\Service\Maintenance\ResetScope;
use App\Tests\Support\Push\ScriptedPushManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The reset gives the provider its registrations back, and does it while it
 * still can.
 *
 * WHAT WENT WRONG
 * ───────────────
 * `ResetDataCommand` had zero references to any push manager. It truncated the
 * account and calendar rows and left Google and Microsoft holding live
 * registrations pointed at this install, which then delivered notifications
 * nothing could resolve — a steady trickle of "notification for an unknown
 * channel" that no amount of restarting would stop, because the credentials
 * needed to call the channels off had been truncated along with them.
 *
 * WHY THE ORDERING IS THE ASSERTION
 * ─────────────────────────────────
 * "It revokes" is not enough on its own. Revoking AFTER the truncate would pass
 * a naive test and fix nothing at all: by then the OAuth refresh token is gone,
 * so every revocation call fails and the channels stay live exactly as before.
 * testTheTokenIsStillThereWhenTheRevocationHappens is the one that would catch
 * that, which is why the stub records the token rather than only the call.
 */
final class ResetHandsBackRegistrationsTest extends KernelTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private DataResetter $resetter;
    private ScriptedPushManager $push;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->resetter   = $container->get(DataResetter::class);
        $this->push       = $container->get(ScriptedPushManager::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        // Everything below runs inside a transaction that is rolled back. A
        // reset truncates real tables, so this is not optional tidiness.
        $this->connection->beginTransaction();

        $this->account($user, 'reset-one' . ScriptedPushManager::MARKER . 'joder.dev');
        $this->account($user, 'reset-two' . ScriptedPushManager::MARKER . 'joder.dev');
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * A reset that deletes accounts revokes their push first.
     */
    public function testDeletingAccountsRevokesTheirPush(): void
    {
        $report = $this->resetter->reset(new ResetScope(accounts: true));

        self::assertCount(2, $this->push->revoked, 'both accounts were handed back');
        self::assertSame(2, $report->pushRevoked, 'and the report says so');
    }

    /**
     * The ordering, proved rather than assumed. See the class docblock: a
     * revocation that happens after the truncate is a revocation that cannot
     * work, and it would look identical from the outside.
     */
    public function testTheTokenIsStillThereWhenTheRevocationHappens(): void
    {
        $this->resetter->reset(new ResetScope(accounts: true));

        self::assertNotSame([], $this->push->revoked);

        foreach ($this->push->revoked as $call) {
            self::assertTrue(
                $call['hadToken'],
                $call['email'] . ' was revoked while its credential still existed',
            );
        }
    }

    /**
     * The half that keeps the fix from being worse than the bug.
     *
     * The tokens may already be dead; the provider may 404 a channel that
     * expired on its own; the network may be down. None of that is a reason to
     * refuse a destructive operation somebody explicitly asked for — a reset
     * that will not run because Google is unreachable is a far worse failure
     * than a channel left to lapse, which it does within a week anyway.
     */
    public function testTheResetStillCompletesWhenRevocationFails(): void
    {
        $this->push->failEveryRevocation = true;

        $report = $this->resetter->reset(new ResetScope(accounts: true));

        self::assertCount(2, $this->push->revoked, 'both were still attempted');
        self::assertSame(0, $report->pushRevoked, 'none is claimed to have succeeded');

        // And the work the user actually asked for happened anyway.
        self::assertContains('account', $report->truncatedTables());
        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM account'),
            'the accounts are gone despite every revocation failing',
        );
    }

    /**
     * A plain reset keeps the accounts and calendars, so their registrations
     * are still valid and must NOT be torn down. Revoking here would turn a
     * data reset into a push outage lasting until the next renewal sweep.
     */
    public function testAResetThatKeepsAccountsRevokesNothing(): void
    {
        $this->resetter->reset(new ResetScope());

        self::assertSame([], $this->push->revoked);
    }

    private function account(User $user, string $email): Account
    {
        $account = new Account();

        $account->usr               = $user;
        $account->name              = $email;
        $account->username          = $email;
        $account->email             = $email;
        $account->authType          = AuthType::OAuth2->value;
        $account->oauthProvider     = MailProvider::Google->value;
        $account->oauthAccessToken  = 'access-token';
        $account->oauthRefreshToken = 'refresh-token';
        $account->isActive          = true;
        $account->pushEnabled       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
