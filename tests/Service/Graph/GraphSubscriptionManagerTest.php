<?php

declare(strict_types=1);

namespace App\Tests\Service\Graph;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\PushHealth;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Graph\GraphSubscriptionManager;
use App\Service\Mail\GraphApiClient;
use App\Service\Setup\PublicUrlSetting;
use App\Tests\Support\Log\RecordingLogger;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Whether an Outlook account is on push or quietly back on polling.
 *
 * Every failure in here is designed to be non-fatal — a subscription that
 * cannot be created leaves the account polling, one that cannot be deleted is
 * left to lapse — which is right, and also means nothing ever throws to say
 * push has stopped. The only evidence is what these methods return and what
 * they leave on the account, so that is what is pinned.
 *
 * The remote half is a stub: this is about the decisions and the state, not
 * about Graph's wire format, which GraphApiClient's own tests cover.
 */
final class GraphSubscriptionManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private GraphSubscriptionManager $manager;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->manager    = $container->get(GraphSubscriptionManager::class);

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
     * Health is the panel's summary of push, and its three states have to stay
     * distinct: never set up, set up and lapsed, working. Collapsing the middle
     * one into either of the others is how a dead subscription reads as a
     * deliberate choice.
     */
    public function testHealthTellsInactiveFromLapsedFromWorking(): void
    {
        $this->account->pushEnabled = false;
        self::assertSame(PushHealth::Inactive, $this->manager->health($this->account));

        $this->account->pushEnabled = true;
        self::assertSame(
            PushHealth::Inactive,
            $this->manager->health($this->account),
            'push on but never subscribed is not a degraded subscription',
        );

        // Lapsed, not Degraded, now that the two are told apart: the expiry has
        // passed, which is a fact and says renewal did not run. Graph has no
        // Degraded case at all — it validates the callback URL when the
        // subscription is created, so a live unexpired one is a reachable one.
        $this->account->graphSubscriptionId = 'sub-1';
        $this->account->graphSubscriptionExpiresAt = new DateTimeImmutable('-1 hour');
        self::assertSame(PushHealth::Lapsed, $this->manager->health($this->account));
        self::assertTrue(
            $this->manager->health($this->account)->needsRepair(),
            'and it is one of the states --repair re-registers',
        );

        $this->account->graphSubscriptionExpiresAt = new DateTimeImmutable('+2 days');
        self::assertSame(PushHealth::Active, $this->manager->health($this->account));
    }

    /**
     * Renewal has to happen before expiry, not at it: Graph will not revive a
     * lapsed subscription, so a threshold that waits until the last minute is
     * one missed scheduler run away from silently dropping push.
     */
    public function testRenewalIsDueBeforeTheSubscriptionLapses(): void
    {
        $this->account->pushEnabled = true;

        $this->account->graphSubscriptionExpiresAt = new DateTimeImmutable(
            sprintf('+%d minutes', GraphSubscriptionManager::RENEW_THRESHOLD_MINUTES - 60),
        );
        self::assertTrue($this->manager->needsRenewal($this->account));

        $this->account->graphSubscriptionExpiresAt = new DateTimeImmutable(
            sprintf('+%d minutes', GraphSubscriptionManager::RENEW_THRESHOLD_MINUTES + 60),
        );
        self::assertFalse($this->manager->needsRenewal($this->account));

        // Nothing to renew yet is still "due" — that is what makes the
        // scheduler establish push on an account that has never had it.
        $this->account->graphSubscriptionExpiresAt = null;
        self::assertTrue($this->manager->needsRenewal($this->account));

        // …but never for an account that has opted out.
        $this->account->pushEnabled = false;
        self::assertFalse($this->manager->needsRenewal($this->account));
    }

    /**
     * The guard that keeps a dev machine off Microsoft's retry queue: without a
     * publicly reachable URL there is nowhere to deliver, and Graph validates
     * the endpoint synchronously, so attempting it can only fail slowly.
     */
    public function testWithoutAPublicUrlItStaysOnPollingRatherThanTrying(): void
    {
        $this->account->pushEnabled = true;

        self::assertFalse($this->manager->subscribe($this->account));
        self::assertNull($this->account->graphSubscriptionId);
    }

    /** An account that has not asked for push is never subscribed. */
    public function testPushOffMeansNoSubscription(): void
    {
        $this->account->pushEnabled = false;

        self::assertFalse($this->manager->subscribe($this->account));
        self::assertNull($this->account->graphSubscriptionId);
    }

    /**
     * Unsubscribing has to clear local state even when the remote call fails,
     * or the account keeps a subscription id it no longer owns — which is
     * exactly the state that produces "unknown subscription" notifications
     * nobody can act on.
     */
    public function testTeardownClearsLocalStateWhateverTheRemoteSays(): void
    {
        $this->account->pushEnabled                = true;
        $this->account->graphSubscriptionId        = 'sub-1';
        $this->account->graphSubscriptionClientState = 'secret';
        $this->account->graphSubscriptionExpiresAt = new DateTimeImmutable('+1 day');

        $this->em->flush();

        // No HTTP is stubbed, so the delete fails — which is the interesting
        // case, not the happy one.
        $this->manager->unsubscribe($this->account);

        self::assertNull($this->account->graphSubscriptionId);
        self::assertNull($this->account->graphSubscriptionClientState);
        self::assertNull($this->account->graphSubscriptionExpiresAt);
    }

    /** Nothing to tear down is not an error, and must not call out. */
    public function testTeardownWithNoSubscriptionDoesNothing(): void
    {
        $this->manager->unsubscribe($this->account);

        self::assertNull($this->account->graphSubscriptionId);
    }

    /**
     * Only Microsoft accounts have Graph subscriptions. supports() is what
     * keeps the scheduler from asking Google for one every twelve hours.
     */
    public function testOnlyMicrosoftAccountsAreSupported(): void
    {
        self::assertTrue($this->manager->supports($this->account));

        $this->account->oauthProvider = 'google';
        self::assertFalse($this->manager->supports($this->account));
    }

    /**
     * A renewal that fails for a transient reason hands the old registration
     * back before building a new one.
     *
     * This is the other half of testTeardownClearsLocalStateWhateverTheRemoteSays,
     * and the more expensive half to get wrong. Renewal used to clear local
     * state and let subscribe() do the teardown — but subscribe() returns
     * early when there is no id, so the DELETE never went out. Microsoft went
     * on holding a live subscription that plMail had forgotten, delivering
     * notifications for an id no account matched, for up to three days, over
     * something no admin could act on because the only record of it had been
     * erased.
     *
     * Asserted on the teardown being ATTEMPTED rather than on the account's
     * final state, and that is the whole difficulty: both the fixed and the
     * broken version end with the id null, so the state says nothing. What
     * differs is whether a delete was sent first.
     *
     * The remote calls all fail here — there is no Graph and no token — which
     * is exactly the shape of the bug: the renewal fails for a reason that is
     * NOT a 404, so the registration has to be assumed live.
     */
    public function testATransientRenewalFailureHandsTheOldRegistrationBack(): void
    {
        $this->account->pushEnabled                  = true;
        $this->account->graphSubscriptionId          = 'sub-live';
        $this->account->graphSubscriptionClientState = 'secret';
        $this->account->graphSubscriptionExpiresAt   = new DateTimeImmutable('+1 hour');

        $this->em->flush();

        $logger  = new RecordingLogger();
        $manager = $this->managerLogging($logger);

        $manager->renew($this->account);

        self::assertTrue(
            $logger->sawMessageContaining('teardown'),
            'renewal replaced a subscription that may still be live without handing it back',
        );

        self::assertNull(
            $this->account->graphSubscriptionId,
            'the forgotten id must not be left on the account either',
        );
    }

    /**
     * The same manager, wired to a logger this test can read.
     *
     * Built by hand rather than fetched: what has to be observed is a call the
     * manager makes to a collaborator, and the only trace it leaves is a log
     * line. GraphApiClient is final, so there is no spying on it directly.
     */
    private function managerLogging(RecordingLogger $logger): GraphSubscriptionManager
    {
        $container = self::getContainer();

        return new GraphSubscriptionManager(
            $container->get(GraphApiClient::class),
            $container->get(UrlGeneratorInterface::class),
            $this->em,
            $logger,
            $container->get(PublicUrlSetting::class),
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'push-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Push';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);

        $account                   = new Account();
        $account->usr              = $user;
        $account->name             = 'Push fixture';
        $account->email            = 'push@example.test';
        $account->username         = uniqid('push-', true);
        $account->imapHost         = 'outlook.office365.com';
        $account->imapPort         = 993;
        $account->imapEncryption   = 'ssl';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $account->isActive         = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
