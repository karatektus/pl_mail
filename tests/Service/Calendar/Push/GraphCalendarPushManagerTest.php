<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Push;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Calendar\Push\GraphCalendarPushManager;
use App\Service\Calendar\Push\PushCallbackUrl;
use App\Service\OAuth\OAuthTokenManager;
use App\Tests\Service\Calendar\RecordingLogger;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A Graph calendar subscription is per calendar, is renewed rather than
 * replaced, and expires in under three days.
 *
 * Those three facts are the whole subject. The resource subscribed to is
 * `me/calendars/{id}/events`, which is why this cannot be the account-level
 * registration GraphSubscriptionManager already makes for mail — one mailbox
 * mirroring six calendars needs six of these, each with its own secret.
 *
 * Renewal is a PATCH of expirationDateTime, and the expiry stored is the one
 * Graph answered with. A local constant believed instead is a subscription that
 * stops delivering while every dashboard still calls it healthy — and Microsoft
 * will not revive one that has lapsed, so the fallback to a fresh subscription
 * when the PATCH is refused is the only thing standing between an expired
 * registration and a calendar that polls forever.
 *
 * The other claim is the one the interface states in prose: a subscription that
 * cannot be created leaves the calendar on the fifteen-minute sweep and does not
 * throw. Graph validates the notification URL synchronously, so this is the
 * ordinary outcome on any install that is not reachable from the internet, and
 * it must be ordinary in the code too.
 *
 * Real container, real database, MockHttpClient. The behaviour worth pinning is
 * the row a later query finds and the request body Microsoft was actually sent.
 */
final class GraphCalendarPushManagerTest extends KernelTestCase
{
    private const string PUBLIC_URL = 'https://mail.example.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private PushCallbackUrl $callback;
    private RecordingLogger $logger;
    private Calendar $calendar;

    /** @var list<array{method: string, url: string, body: array<string,mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $_SERVER['APP_PUBLIC_URL'] = self::PUBLIC_URL;

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->callback   = $container->get(PushCallbackUrl::class);
        $this->logger     = new RecordingLogger();

        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_PUBLIC_URL']);

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testASubscriptionStoresTheExpiryGraphGrantedRatherThanTheOneAskedFor(): void
    {
        $granted = new DateTimeImmutable('+2 days');

        $manager = $this->manager([$this->subscriptionResponse('sub-1', $granted)]);

        self::assertTrue($manager->subscribe($this->calendar));

        self::assertSame('sub-1', $this->calendar->pushChannelId);
        self::assertNotNull($this->calendar->pushSecret);
        self::assertNull($this->calendar->pushResourceId, 'Microsoft cancels by subscription id alone');
        self::assertNotNull($this->calendar->pushExpiresAt);

        self::assertEqualsWithDelta(
            $granted->getTimestamp(),
            $this->calendar->pushExpiresAt->getTimestamp(),
            2,
            'the stored expiry must be Microsoft\'s, not the 4200 minutes asked for',
        );
    }

    public function testTheSubscriptionNamesThisCalendarsEventsAndEveryKindOfChange(): void
    {
        $manager = $this->manager([$this->subscriptionResponse('sub-1', new DateTimeImmutable('+2 days'))]);

        self::assertTrue($manager->subscribe($this->calendar));

        $created = $this->requests[0];

        self::assertSame('POST', $created['method']);
        self::assertSame('https://graph.microsoft.com/v1.0/subscriptions', $created['url']);
        self::assertSame('me/calendars/remote-cal-1/events', $created['body']['resource']);
        self::assertSame(self::PUBLIC_URL . '/webhook/graph/calendar', $created['body']['notificationUrl']);

        // Omitting `deleted` produces a calendar that grows but never shrinks,
        // which looks like a working sync until somebody cancels a meeting.
        self::assertSame('created,updated,deleted', $created['body']['changeType']);

        // What the webhook compares against: a subscription registered with a
        // different clientState refuses every notification it causes.
        self::assertSame($this->calendar->pushSecret, $created['body']['clientState']);
    }

    public function testARefusedSubscriptionLeavesTheCalendarPollingInsteadOfFailing(): void
    {
        // The shape an install with no reachable callback answers with: Graph
        // validates the URL synchronously and refuses the create.
        $manager = $this->manager([new MockResponse(
            json_encode(['error' => ['code' => 'InvalidRequest', 'message' => 'Subscription validation request failed.']], JSON_THROW_ON_ERROR),
            ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']],
        )]);

        self::assertFalse($manager->subscribe($this->calendar), 'a refused subscription is "stay on polling", not an error');

        self::assertFalse($this->calendar->hasPushChannel());
        self::assertNotSame([], $this->logger->matching('warning', 'staying on polling'));
    }

    public function testRenewalPatchesTheSubscriptionItAlreadyHasRatherThanMakingASecond(): void
    {
        $extended = new DateTimeImmutable('+2 days');

        $manager = $this->manager([
            $this->subscriptionResponse('sub-1', new DateTimeImmutable('+1 hour')),
            $this->subscriptionResponse('sub-1', $extended),
        ]);

        $manager->subscribe($this->calendar);

        self::assertTrue($manager->renew($this->calendar));

        $renewal = $this->requests[1];

        self::assertSame('PATCH', $renewal['method'], 'a live subscription is extended, not replaced');
        self::assertSame('https://graph.microsoft.com/v1.0/subscriptions/sub-1', $renewal['url']);
        self::assertSame('sub-1', $this->calendar->pushChannelId);

        self::assertEqualsWithDelta(
            $extended->getTimestamp(),
            (int) $this->calendar->pushExpiresAt?->getTimestamp(),
            2,
        );
    }

    public function testASubscriptionMicrosoftHasForgottenIsRecreatedRatherThanLeftDead(): void
    {
        $manager = $this->manager([
            $this->subscriptionResponse('sub-1', new DateTimeImmutable('+1 hour')),
            // The PATCH, refused: Graph will not revive a lapsed subscription.
            new MockResponse(
                json_encode(['error' => ['code' => 'ResourceNotFound']], JSON_THROW_ON_ERROR),
                ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']],
            ),
            $this->subscriptionResponse('sub-2', new DateTimeImmutable('+2 days')),
        ]);

        $manager->subscribe($this->calendar);

        self::assertTrue($manager->renew($this->calendar));

        self::assertSame('sub-2', $this->calendar->pushChannelId, 'a failed renewal has to fall back to a fresh subscription');
        self::assertSame('POST', $this->requests[2]['method']);
    }

    public function testRenewalIsDueOnlyWhenMicrosoftsOwnExpiryIsClose(): void
    {
        $manager = $this->manager([]);

        self::assertTrue($manager->needsRenewal($this->calendar), 'a calendar with no subscription needs one');

        $this->calendar->pushChannelId = 'sub-1';
        $this->calendar->pushSecret    = 'secret';
        $this->calendar->pushExpiresAt = new DateTimeImmutable('+2 days');

        self::assertFalse($manager->needsRenewal($this->calendar));

        $this->calendar->pushExpiresAt = new DateTimeImmutable('+6 hours');

        self::assertTrue($manager->needsRenewal($this->calendar));
    }

    public function testUnsubscribingDeletesTheSubscriptionAndForgetsIt(): void
    {
        $manager = $this->manager([
            $this->subscriptionResponse('sub-1', new DateTimeImmutable('+2 days')),
            new MockResponse('', ['http_code' => 204]),
        ]);

        $manager->subscribe($this->calendar);
        $manager->unsubscribe($this->calendar);

        self::assertSame('DELETE', $this->requests[1]['method']);
        self::assertSame('https://graph.microsoft.com/v1.0/subscriptions/sub-1', $this->requests[1]['url']);
        self::assertFalse($this->calendar->hasPushChannel());
    }

    public function testASubscriptionMicrosoftWillNotDeleteIsForgottenAnyway(): void
    {
        $manager = $this->manager([
            $this->subscriptionResponse('sub-1', new DateTimeImmutable('+2 days')),
            new MockResponse('{"error":{"code":"ServiceUnavailable"}}', ['http_code' => 503, 'response_headers' => ['content-type' => 'application/json']]),
        ]);

        $manager->subscribe($this->calendar);
        $manager->unsubscribe($this->calendar);

        self::assertFalse($this->calendar->hasPushChannel(), 'a teardown must not be blocked by the remote');
    }

    public function testWithoutAPublicHttpsAddressNothingIsRegisteredAndNothingIsAsked(): void
    {
        $_SERVER['APP_PUBLIC_URL'] = 'https://localhost';

        $manager = $this->manager([]);

        self::assertFalse($manager->isConfigured(), 'Microsoft will never reach a loopback address');
        self::assertFalse($manager->subscribe($this->calendar));
        self::assertSame([], $this->requests);
    }

    public function testAGoogleCalendarIsNotClaimed(): void
    {
        $manager = $this->manager([]);

        self::assertTrue($manager->supports($this->calendar));

        $account = $this->calendar->account;

        self::assertNotNull($account);
        $account->oauthProvider = MailProvider::Google->value;

        self::assertFalse($manager->supports($this->calendar));
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * @param list<MockResponse> $responses
     */
    private function manager(array $responses): GraphCalendarPushManager
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $body = json_decode((string) ($options['body'] ?? ''), true);

            $this->requests[] = [
                'method' => $method,
                'url'    => $url,
                'body'   => true === is_array($body) ? $body : [],
            ];

            $response = array_shift($responses);

            self::assertNotNull($response, sprintf('unexpected request: %s %s', $method, $url));

            return $response;
        });

        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('test-token');

        return new GraphCalendarPushManager(
            $http,
            $tokens,
            $this->callback,
            $this->em,
            $this->logger,
        );
    }

    private function subscriptionResponse(string $id, DateTimeImmutable $expiration): MockResponse
    {
        return new MockResponse(
            json_encode([
                'id'                 => $id,
                'resource'           => 'me/calendars/remote-cal-1/events',
                'expirationDateTime' => $expiration->format('Y-m-d\TH:i:s.u\Z'),
            ], JSON_THROW_ON_ERROR),
            ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'graph-calendar-push-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'Push';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                = new Account();
        $account->usr           = $user;
        $account->email         = $user->email;
        $account->username      = $user->email;
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = MailProvider::Microsoft->value;
        $account->isActive      = true;
        $this->em->persist($account);

        $this->calendar           = new Calendar();
        $this->calendar->usr      = $user;
        $this->calendar->account  = $account;
        $this->calendar->name     = 'Team';
        $this->calendar->role     = CalendarRole::Remote;
        $this->calendar->remoteId = 'remote-cal-1';
        $this->em->persist($this->calendar);

        $this->em->flush();
    }
}
