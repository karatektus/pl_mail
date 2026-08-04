<?php

declare(strict_types=1);

namespace App\Tests\Controller\Webhook;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * An endpoint anybody on the internet can POST to must prove the caller before
 * it does anything, and must do nothing at all on the one notification that
 * reports nothing.
 *
 * Two claims, and both of them are the kind that passes review by inspection
 * and fails in production:
 *
 *   **The channel token is the only authentication there is.** Without the
 *   comparison, a POST carrying a guessed or leaked channel id triggers a full
 *   calendar sync — Google API calls, on somebody else's grant, at whatever
 *   rate the caller likes. That is the bug GmailPushController was written to
 *   close for mail, and it is exactly as available here.
 *
 *   **The first notification after registering is a handshake.** Google sends
 *   `X-Goog-Resource-State: sync` the moment a channel opens, meaning only
 *   "the channel is open". Syncing on it puts a full calendar read in the queue
 *   for every registration and every hourly renewal in the install — the sort
 *   of load that is invisible until an install with forty calendars notices its
 *   queue never empties.
 *
 * Asserted on the queue rather than on the status code, deliberately: a refusal
 * that still dispatched would answer 403 and sync anyway, and the status is the
 * half of that a test would otherwise be happy with.
 */
final class GoogleCalendarPushControllerTest extends WebTestCase
{
    private const string PATH = '/webhook/google/calendar';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Calendar $calendar;
    private string $token;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAValidNotificationDispatchesOneSyncForThatCalendar(): void
    {
        $client = $this->seed();

        $client->request('POST', self::PATH, server: $this->headers($this->calendar->pushChannelId, $this->token, 'exists'));

        self::assertResponseStatusCodeSame(204);

        $messages = $this->dispatched();

        self::assertCount(1, $messages, 'a notification is one sync, not none and not several');
        self::assertInstanceOf(SyncCalendarMessage::class, $messages[0]);
        self::assertSame((int) $this->calendar->id, $messages[0]->calendarId);
    }

    public function testTheSyncHandshakeIsIgnoredRatherThanTreatedAsAChange(): void
    {
        $client = $this->seed();

        $client->request('POST', self::PATH, server: $this->headers($this->calendar->pushChannelId, $this->token, 'sync'));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->dispatched(), 'the opening handshake reports nothing, so there is nothing to sync');
    }

    public function testANotificationWithTheWrongTokenIsRefusedAndSyncsNothing(): void
    {
        $client = $this->seed();

        $client->request('POST', self::PATH, server: $this->headers($this->calendar->pushChannelId, str_repeat('a', 64), 'exists'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->dispatched(), 'a forged notification must not be able to trigger provider work');
    }

    public function testANotificationWithNoTokenAtAllIsRefusedRatherThanTrusted(): void
    {
        $client = $this->seed();

        $headers = $this->headers($this->calendar->pushChannelId, null, 'exists');

        $client->request('POST', self::PATH, server: $headers);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->dispatched(), 'failing closed is the only safe default on an unauthenticated endpoint');
    }

    public function testAChannelPlMailNoLongerHoldsIsRefusedRatherThanIgnoredQuietly(): void
    {
        $client = $this->seed();

        $client->request('POST', self::PATH, server: $this->headers('a-channel-nobody-owns', $this->token, 'exists'));

        // 404 on purpose: Google keeps delivering on a channel for up to a week
        // and repeated errors are the only thing that makes it stop. There is
        // nothing else that can be done — cancelling needs the resourceId, and
        // that went with the row.
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->dispatched());
    }

    public function testANotificationWithNoChannelIdIsRejectedBeforeAnyLookup(): void
    {
        $client = $this->seed();

        $client->request('POST', self::PATH, server: ['HTTP_X-Goog-Resource-State' => 'exists']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->dispatched());
    }

    public function testACalendarThatNoLongerMirrorsAnythingIsNotSynced(): void
    {
        $client = $this->seed();

        // The channel outlived the mirroring: the calendar was unsubscribed and
        // the row kept. Dispatching would fail in the handler instead of here.
        $this->calendar->remoteId = null;
        $this->em->flush();

        $client->request('POST', self::PATH, server: $this->headers($this->calendar->pushChannelId, $this->token, 'exists'));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->dispatched());
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * @return array<string,string>
     */
    private function headers(?string $channelId, ?string $token, string $state): array
    {
        $headers = ['HTTP_X-Goog-Resource-State' => $state];

        if (null !== $channelId) {
            $headers['HTTP_X-Goog-Channel-ID'] = $channelId;
        }

        if (null !== $token) {
            $headers['HTTP_X-Goog-Channel-Token'] = $token;
        }

        return $headers;
    }

    /**
     * @return list<object>
     */
    private function dispatched(): array
    {
        $transport = self::getContainer()->get('messenger.transport.ingest');

        // in-memory:// in the test environment, so nothing is really queued —
        // asserted rather than cast, because a real transport here would make
        // every assertion below vacuously true.
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(
            static fn (object $envelope): object => $envelope->getMessage(),
            $transport->getSent(),
        ));
    }

    private function seed(): KernelBrowser
    {
        $client = static::createClient();

        // One kernel for the whole test: the client reboots between requests by
        // default, which detaches the EntityManager holding these fixtures and
        // resets the in-memory transport the assertions read.
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'google-push-hook-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Google';
        $user->nameLast  = 'Hook';
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

        $this->token = bin2hex(random_bytes(32));

        $this->calendar                = new Calendar();
        $this->calendar->usr           = $user;
        $this->calendar->account       = $account;
        $this->calendar->name          = 'Work';
        $this->calendar->role          = CalendarRole::Remote;
        $this->calendar->remoteId      = 'remote-cal-1';
        $this->calendar->pushChannelId = bin2hex(random_bytes(16));
        $this->calendar->pushResourceId = 'res-1';
        $this->calendar->pushSecret    = $this->token;
        $this->calendar->pushExpiresAt = new \DateTimeImmutable('+7 days');
        $this->em->persist($this->calendar);

        $this->em->flush();

        return $client;
    }
}
