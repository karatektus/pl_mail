<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Push;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Calendar\Push\GoogleCalendarPushManager;
use App\Service\Calendar\Push\PushCallbackUrl;
use App\Service\Calendar\Sync\Google\GoogleCalendarApiClient;
use App\Service\OAuth\OAuthTokenManager;
use App\Tests\Service\Calendar\RecordingLogger;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A watch channel is worth having only if plMail can renew it and stop it, and
 * both of those depend entirely on believing Google over believing ourselves.
 *
 * That is the claim. Two things Google answers with cannot be known in advance
 * and are not optional:
 *
 *   `expiration` — the channel's real end. Google may grant less than the ttl
 *   asked for, and renewal driven off the local constant instead is a channel
 *   that dies quietly some hours before anything tries to replace it. Every
 *   run afterwards looks healthy and nothing arrives.
 *
 *   `resourceId` — half of the pair channels/stop needs. Discard it and the
 *   channel can never be closed: it delivers for its whole week to an endpoint
 *   holding a different secret, so every notification is refused and logged as
 *   a forgery, and re-registering adds a second one beside it.
 *
 * The rest is the contract the interface states in prose and this pins in code:
 * a registration that fails leaves the calendar polling and does not throw,
 * because there is no deployment in which refusing to sync a calendar over an
 * unverified callback domain is the better answer.
 *
 * Built against a real container, a real database and a MockHttpClient rather
 * than doubles. GoogleCalendarApiClient is final and cannot be mocked, the
 * behaviour worth pinning is what a later query finds on the row, and the
 * request the mock captures is the only place the payload Google is actually
 * sent can be inspected.
 */
final class GoogleCalendarPushManagerTest extends KernelTestCase
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

        // PublicUrlSetting resolves this per call and lets a real environment
        // value win, so setting it here is enough — and it is restored in
        // tearDown, because InstallEmptyInstallTest writes the same key.
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

    public function testARegisteredChannelStoresTheExpiryGoogleGrantedRatherThanTheOneAskedFor(): void
    {
        $granted = new DateTimeImmutable('+3 days');

        $manager = $this->manager([$this->watchResponse('res-1', $granted)]);

        self::assertTrue($manager->subscribe($this->calendar));

        self::assertNotNull($this->calendar->pushChannelId);
        self::assertSame('res-1', $this->calendar->pushResourceId, 'without the resourceId the channel can never be stopped');
        self::assertNotNull($this->calendar->pushSecret);
        self::assertNotNull($this->calendar->pushExpiresAt);

        self::assertEqualsWithDelta(
            $granted->getTimestamp(),
            $this->calendar->pushExpiresAt->getTimestamp(),
            2,
            'the stored expiry must be Google\'s, not the week the ttl asked for',
        );
    }

    public function testTheChannelIsRegisteredAgainstTheConfiguredPublicAddressAndCarriesItsOwnToken(): void
    {
        $manager = $this->manager([$this->watchResponse('res-1', new DateTimeImmutable('+7 days'))]);

        self::assertTrue($manager->subscribe($this->calendar));

        $watch = $this->requests[0];

        self::assertSame('POST', $watch['method']);
        self::assertStringContainsString('/calendars/remote-cal-1/events/watch', $watch['url']);
        self::assertSame('web_hook', $watch['body']['type']);
        self::assertSame(self::PUBLIC_URL . '/webhook/google/calendar', $watch['body']['address']);
        self::assertSame(['ttl' => '604800'], $watch['body']['params'], 'ttl belongs under params, not at the top level');

        // The token sent is the secret stored: the webhook compares one against
        // the other, so a registration that sent a different value would refuse
        // every notification it caused.
        self::assertSame($this->calendar->pushSecret, $watch['body']['token']);
        self::assertSame($this->calendar->pushChannelId, $watch['body']['id']);
    }

    public function testARefusedRegistrationLeavesTheCalendarPollingInsteadOfFailing(): void
    {
        // The shape an unverified callback domain arrives in, and the one a
        // grant without the calendar scope does.
        $manager = $this->manager([new MockResponse(
            json_encode(['error' => ['code' => 403, 'errors' => [['reason' => 'pushNotSupported', 'message' => 'Channel domain not verified']]]], JSON_THROW_ON_ERROR),
            ['http_code' => 403, 'response_headers' => ['content-type' => 'application/json']],
        )]);

        self::assertFalse($manager->subscribe($this->calendar), 'a refused watch is "stay on polling", not an error');

        self::assertNull($this->calendar->pushChannelId);
        self::assertNull($this->calendar->pushSecret);
        self::assertNull($this->calendar->pushExpiresAt);
        self::assertNotSame([], $this->logger->matching('warning', 'staying on polling'));
    }

    public function testAChannelWithNoResourceIdIsNotStoredBecauseItCouldNeverBeStopped(): void
    {
        $manager = $this->manager([new MockResponse(
            json_encode(['id' => 'whatever', 'expiration' => '9999999999999'], JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        )]);

        self::assertFalse($manager->subscribe($this->calendar));
        self::assertNull($this->calendar->pushChannelId, 'half a registration is worse than none');
    }

    public function testRenewalOpensANewChannelAndStopsTheOneItReplaces(): void
    {
        $manager = $this->manager([
            $this->watchResponse('res-1', new DateTimeImmutable('+7 days')),
            new MockResponse('', ['http_code' => 204]),
            $this->watchResponse('res-2', new DateTimeImmutable('+7 days')),
        ]);

        self::assertTrue($manager->subscribe($this->calendar));
        $first = (string) $this->calendar->pushChannelId;

        self::assertTrue($manager->renew($this->calendar));

        self::assertSame('res-2', $this->calendar->pushResourceId);
        self::assertNotSame($first, $this->calendar->pushChannelId, 'a channel is renewed by replacing it');

        // The stop, in the middle, naming the channel being replaced. Without
        // it Google keeps delivering on the old one for a week against a secret
        // that no longer matches.
        $stop = $this->requests[1];

        self::assertStringContainsString('/channels/stop', $stop['url']);
        self::assertSame(['id' => $first, 'resourceId' => 'res-1'], $stop['body']);
    }

    public function testRenewalIsDueOnlyWhenGooglesOwnExpiryIsClose(): void
    {
        $manager = $this->manager([]);

        self::assertTrue($manager->needsRenewal($this->calendar), 'a calendar with no channel needs one');

        $this->calendar->pushChannelId = 'chan';
        $this->calendar->pushSecret    = 'secret';
        $this->calendar->pushExpiresAt = new DateTimeImmutable('+6 days');

        self::assertFalse($manager->needsRenewal($this->calendar));

        $this->calendar->pushExpiresAt = new DateTimeImmutable('+2 hours');

        self::assertTrue($manager->needsRenewal($this->calendar));

        $this->calendar->pushExpiresAt = null;

        self::assertTrue($manager->needsRenewal($this->calendar), 'an unknown expiry is renewed now rather than trusted');
    }

    public function testUnsubscribingStopsTheChannelAndForgetsEveryPartOfIt(): void
    {
        $manager = $this->manager([
            $this->watchResponse('res-1', new DateTimeImmutable('+7 days')),
            new MockResponse('', ['http_code' => 204]),
        ]);

        $manager->subscribe($this->calendar);
        $channelId = (string) $this->calendar->pushChannelId;

        $manager->unsubscribe($this->calendar);

        $stop = $this->requests[1];

        self::assertStringContainsString('/channels/stop', $stop['url']);
        self::assertSame(['id' => $channelId, 'resourceId' => 'res-1'], $stop['body']);

        self::assertNull($this->calendar->pushChannelId);
        self::assertNull($this->calendar->pushResourceId);
        self::assertNull($this->calendar->pushSecret);
        self::assertNull($this->calendar->pushExpiresAt);
    }

    public function testAChannelGoogleWillNotStopIsForgottenAnyway(): void
    {
        $manager = $this->manager([
            $this->watchResponse('res-1', new DateTimeImmutable('+7 days')),
            new MockResponse('{"error":{"code":500}}', ['http_code' => 500, 'response_headers' => ['content-type' => 'application/json']]),
        ]);

        $manager->subscribe($this->calendar);
        $manager->unsubscribe($this->calendar);

        // Swallowed by contract: a channel that cannot be stopped lapses on its
        // own, and refusing to unsubscribe a calendar over it would be worse.
        self::assertFalse($this->calendar->hasPushChannel());
    }

    public function testWithoutAPublicHttpsAddressNothingIsRegisteredAndNothingIsAsked(): void
    {
        $_SERVER['APP_PUBLIC_URL'] = 'http://localhost:8000';

        $manager = $this->manager([]);

        self::assertFalse($manager->isConfigured());
        self::assertFalse($manager->subscribe($this->calendar));
        self::assertSame([], $this->requests, 'an unreachable install must not spend a request finding that out');
        self::assertNotSame([], $this->logger->matching('warning', 'staying on polling'));
    }

    public function testACalendarBehindAConnectionIsNotClaimed(): void
    {
        $manager = $this->manager([]);

        self::assertTrue($manager->supports($this->calendar));

        $this->calendar->account = null;

        self::assertFalse($manager->supports($this->calendar), 'CalDAV has no watch channels');
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * @param list<MockResponse> $responses
     */
    private function manager(array $responses): GoogleCalendarPushManager
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

        return new GoogleCalendarPushManager(
            new GoogleCalendarApiClient($http, $tokens),
            $this->callback,
            $this->em,
            $this->logger,
        );
    }

    /** Google answers a watch with the channel it made, expiration in milliseconds. */
    private function watchResponse(string $resourceId, DateTimeImmutable $expiration): MockResponse
    {
        return new MockResponse(
            json_encode([
                'kind'       => 'api#channel',
                'resourceId' => $resourceId,
                'expiration' => (string) ($expiration->getTimestamp() * 1000),
            ], JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'calendar-push-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Calendar';
        $user->nameLast  = 'Push';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                = new Account();
        $account->usr           = $user;
        $account->email         = $user->email;
        $account->username      = $user->email;
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = MailProvider::Google->value;
        $account->isActive      = true;
        $this->em->persist($account);

        $this->calendar           = new Calendar();
        $this->calendar->usr      = $user;
        $this->calendar->account  = $account;
        $this->calendar->name     = 'Work';
        $this->calendar->role     = CalendarRole::Remote;
        $this->calendar->remoteId = 'remote-cal-1';
        $this->em->persist($this->calendar);

        $this->em->flush();
    }
}
