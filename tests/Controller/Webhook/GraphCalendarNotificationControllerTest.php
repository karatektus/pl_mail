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
 * The Graph calendar webhook has to answer a handshake it will only ever be
 * asked once, and refuse everything that cannot prove itself.
 *
 * The handshake first, because getting it wrong is invisible afterwards: Graph
 * POSTs `?validationToken=…` synchronously while the subscription is being
 * created and expects the raw token back as text/plain within ten seconds. An
 * endpoint that answers it with JSON, with a 202, or with a body wrapped in
 * anything at all never gets a subscription in the first place — and the
 * failure surfaces as "push does not work on Microsoft accounts", nowhere near
 * this code.
 *
 * Then clientState. It is the only authentication on a route the internet can
 * reach; without the comparison, a POST naming a guessed subscription id
 * triggers a full calendar sync against somebody else's Microsoft grant.
 *
 * The dedup claim is smaller but real: Graph batches, and six changes to one
 * calendar arrive in one POST. Six identical delta syncs would be harmless and
 * pointless, on a queue that also carries mail.
 */
final class GraphCalendarNotificationControllerTest extends WebTestCase
{
    private const string PATH = '/webhook/graph/calendar';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Calendar $calendar;
    private string $clientState;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheValidationHandshakeIsEchoedBackAsPlainText(): void
    {
        $client = $this->seed();

        $client->request('POST', self::PATH . '?validationToken=' . urlencode('opaque token 42'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=utf-8');
        self::assertSame('opaque token 42', $client->getResponse()->getContent());
        self::assertSame([], $this->dispatched(), 'a validation request describes no change');
    }

    public function testAValidNotificationDispatchesOneSyncForThatCalendar(): void
    {
        $client = $this->seed();

        $this->post($client, [
            ['subscriptionId' => $this->calendar->pushChannelId, 'clientState' => $this->clientState],
        ]);

        self::assertResponseStatusCodeSame(202);

        $messages = $this->dispatched();

        self::assertCount(1, $messages);
        self::assertInstanceOf(SyncCalendarMessage::class, $messages[0]);
        self::assertSame((int) $this->calendar->id, $messages[0]->calendarId);
    }

    public function testANotificationWithTheWrongClientStateSyncsNothing(): void
    {
        $client = $this->seed();

        $this->post($client, [
            ['subscriptionId' => $this->calendar->pushChannelId, 'clientState' => str_repeat('b', 64)],
        ]);

        self::assertSame([], $this->dispatched(), 'a forged notification must not be able to trigger provider work');
    }

    public function testANotificationWithNoClientStateAtAllSyncsNothing(): void
    {
        $client = $this->seed();

        $this->post($client, [
            ['subscriptionId' => $this->calendar->pushChannelId],
        ]);

        self::assertSame([], $this->dispatched(), 'failing closed is the only safe default on an unauthenticated endpoint');
    }

    public function testASubscriptionPlMailNoLongerHoldsSyncsNothing(): void
    {
        $client = $this->seed();

        $this->post($client, [
            ['subscriptionId' => 'a-subscription-nobody-owns', 'clientState' => $this->clientState],
        ]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame([], $this->dispatched());
    }

    public function testABatchAboutOneCalendarIsOneSyncRatherThanFour(): void
    {
        $client = $this->seed();

        $notification = ['subscriptionId' => $this->calendar->pushChannelId, 'clientState' => $this->clientState];

        $this->post($client, [$notification, $notification, $notification, $notification]);

        self::assertCount(1, $this->dispatched(), 'four changes to one calendar are one delta read');
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $notifications
     */
    private function post(KernelBrowser $client, array $notifications): void
    {
        $client->request(
            'POST',
            self::PATH,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['value' => $notifications], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return list<object>
     */
    private function dispatched(): array
    {
        $transport = self::getContainer()->get('messenger.transport.ingest');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(
            static fn (object $envelope): object => $envelope->getMessage(),
            $transport->getSent(),
        ));
    }

    private function seed(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'graph-calendar-hook-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Graph';
        $user->nameLast  = 'Hook';
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

        $this->clientState = bin2hex(random_bytes(32));

        $this->calendar                = new Calendar();
        $this->calendar->usr           = $user;
        $this->calendar->account       = $account;
        $this->calendar->name          = 'Team';
        $this->calendar->role          = CalendarRole::Remote;
        $this->calendar->remoteId      = 'remote-cal-1';
        $this->calendar->pushChannelId = 'sub-' . bin2hex(random_bytes(8));
        $this->calendar->pushSecret    = $this->clientState;
        $this->calendar->pushExpiresAt = new \DateTimeImmutable('+2 days');
        $this->em->persist($this->calendar);

        $this->em->flush();

        return $client;
    }
}
