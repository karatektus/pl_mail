<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Calendar\Calendar;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The problems that are NOT emergencies, and the rule that keeps them from
 * being dressed as one.
 *
 * WHY THESE HAVE THEIR OWN FILE
 * ─────────────────────────────
 * A health page that paints "your mail is a few minutes late" the same red as
 * "your mail has stopped" is a page people learn to close, and then the red
 * that mattered goes unread too. So a broken file-store connection is a
 * Warning and not a Critical, and the queue card is not shown at all to
 * somebody who could not act on it.
 *
 * WHAT MOVED OUT, AND WHY
 * ───────────────────────
 * Push used to be this file's archetype: it was a Notice, it did not light the
 * indicator, and the assertion that it did not was called the one worth having.
 * That rested on the old check firing after 36 hours of SILENCE — an inference
 * that was simply wrong on a quiet mailbox, and a level that can be wrong must
 * not interrupt anybody.
 *
 * The push checks no longer infer, they now distinguish a lapsed registration
 * from a live one that is not delivering, and both fire on facts. So push is a
 * Warning, it does light the indicator, and everything asserting on which of
 * the two it is lives in AccountHealthPushVerdictTest. What stays here are the
 * two push rules that were never about severity: push being OFF is a choice,
 * and push is not reported twice when the grant underneath it is dead.
 */
final class AccountHealthQuieterProblemsTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── push: the rules that were never about severity ───────────────────────

    /** Push that is simply off is a choice, not a fault. */
    public function testPushBeingOffIsNotAProblem(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->gmailAccount($user, 'polling@joder.dev');

        $account->pushEnabled = false;
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertSame(0, $crawler->filter('[data-health-issue="push-' . $account->id . '"]')->count());
    }

    /**
     * With the grant dead there is no push to speak of, and the reconnect is
     * the repair for both. Reporting them separately would put two cards on the
     * page for one thing to do.
     */
    public function testPushIsNotReportedSeparatelyWhenTheGrantIsDead(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->gmailAccount($user, 'both@joder.dev');

        $account->pushEnabled            = true;
        $account->gmailWatchExpiry       = new DateTimeImmutable('-1 day');
        $account->oauthLastRefreshError  = 'invalid_grant';
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertSame(1, $crawler->filter('[data-health-issue="account-' . $account->id . '"]')->count());
        self::assertSame(
            0,
            $crawler->filter('[data-health-issue="push-' . $account->id . '"]')->count(),
            'one thing to do, one card',
        );
    }

    // ── integrations ─────────────────────────────────────────────────────────

    /**
     * IntegrationTokenManager already records the failure and already composes
     * "X needs to be reconnected". Its docblock says the settings list should
     * say so; this is that list.
     */
    public function testAnIntegrationThatCouldNotBeRenewedIsReported(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $integration = new Integration($user, Provider::Dropbox, 'Dropbox');
        $integration->recordFailure('Could not renew access to Dropbox: invalid_grant');

        $this->em->persist($integration);
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');
        $card    = $crawler->filter('[data-health-issue="integration-' . $integration->id . '"]');

        self::assertSame(1, $card->count());
        self::assertSame('integration_reconnect', $card->attr('data-health-kind'));
        self::assertSame('warning', $card->attr('data-health-severity'));

        // Mail is explicitly said to be unaffected — the fear this card has to
        // answer is "has something happened to my email?".
        self::assertStringContainsString('mail is unaffected', $card->text());

        // Reconnect goes through the integration OAuth flow that already
        // exists, which upserts onto the same row rather than duplicating it.
        self::assertSame(1, $card->filter('a[href*="/integrations/oauth/"]')->count());
    }

    public function testAWorkingIntegrationIsNotReported(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $integration = new Integration($user, Provider::Nextcloud, 'Nextcloud');
        $integration->recordSuccess();

        $this->em->persist($integration);
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertSame(0, $crawler->filter('[data-health-issue="integration-' . $integration->id . '"]')->count());
    }

    // ── the queue, and who may touch it ──────────────────────────────────────

    /**
     * The failure transport is instance-wide, so the queue card is an admin
     * thing — see AccountHealthInspector::abandonedWork(). A non-admin must not
     * be shown a button that would act on everybody's work.
     */
    public function testANonAdminIsNotOfferedTheQueueRepairs(): void
    {
        $client = static::createClient();
        $this->boot($client);

        // The seeded admin is an admin; log in as somebody who is not.
        $plain = $this->plainUser();
        $client->loginUser($plain);

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            $crawler->filter('[data-health-kind="queue_work_abandoned"]')->count(),
            'the queue is not a per-user question',
        );
    }

    public function testTheQueueRepairsRefuseANonAdmin(): void
    {
        $client = static::createClient();
        $this->boot($client);

        $plain = $this->plainUser();
        $client->loginUser($plain);

        $client->request('POST', '/settings/health/queue/retry', ['_token' => 'anything']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/settings/health/queue/discard', ['_token' => 'anything']);
        self::assertResponseStatusCodeSame(403);
    }

    /** Even an admin needs a token — these are state-changing POSTs. */
    public function testTheQueueRepairsNeedACsrfToken(): void
    {
        $client = static::createClient();
        $this->boot($client);

        $client->request('POST', '/settings/health/queue/retry');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/settings/health/queue/discard');
        self::assertResponseStatusCodeSame(403);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function boot(KernelBrowser $client): User
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->disableReboot();

        $this->connection->beginTransaction();

        $this->em->createQuery('DELETE FROM ' . Calendar::class . ' c WHERE c.usr = :usr')
            ->setParameter('usr', $user)->execute();
        $this->em->createQuery('DELETE FROM ' . Account::class . ' a WHERE a.usr = :usr')
            ->setParameter('usr', $user)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class . ' i WHERE i.usr = :usr')
            ->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    /** A user with ROLE_USER and nothing more. */
    private function plainUser(): User
    {
        $user = new User();

        $user->email     = 'plain-' . bin2hex(random_bytes(4)) . '@joder.dev';
        $user->password  = 'not-a-real-hash';
        $user->nameFirst = 'Plain';
        $user->nameLast  = 'User';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function gmailAccount(User $user, string $email): Account
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

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
