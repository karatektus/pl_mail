<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\CategorySource;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ReclassifyRecentMessage;
use App\Infrastructure\Messaging\Message\ResortMailboxMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Choosing what sorts your mail, and what that choice costs.
 *
 * The setting itself is two columns and would barely be worth a test. What is
 * worth one is the DISPATCH, because it is the difference between a setting
 * that works and one that appears to: without it, mail arriving after the
 * change is sorted the new way and everything already there the old way, so an
 * inbox is filed two ways depending on when each message landed — and the only
 * symptom is tabs that look wrong for reasons nobody can trace back to a
 * radio button they pressed a week ago.
 *
 * And the other half of the same coin: the card submits on change, browsers
 * happily post the option that is already selected, and re-filing a mailbox for
 * that is minutes of a worker spent writing the answers back exactly as they
 * were.
 */
final class CategorySortingTest extends WebTestCase
{
    private const string EMAIL = 'sorting-pref@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;

    /**
     * The client is made HERE and not inside the fixture, because
     * WebTestCase::createClient() refuses to run against a kernel that is
     * already booted — and reaching for the entity manager in setUp() is what
     * boots one.
     */
    protected function setUp(): void
    {
        $this->client = static::createClient();

        // KernelBrowser reboots the kernel between requests by default, which
        // takes the container with it — and with the container, the connection
        // holding this test's transaction and the in-memory transport it is
        // about to inspect. Both have to survive the POST to be worth reading.
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testChoosingTheAssistantIsStoredAndRefilesTheMailbox(): void
    {
        [$client] = $this->signedIn();

        $this->post($client, ['source' => CategorySource::Assistant->value]);

        self::assertResponseRedirects();

        self::assertSame(CategorySource::Assistant->value, $this->reread()->categorySorting->source);

        self::assertCount(1, $this->queued(), 'a changed answer re-files the mail already there');
    }

    public function testOverrulingTheProviderIsStoredAndRefilesTheMailbox(): void
    {
        [$client] = $this->signedIn();

        $this->post($client, ['overrideProvider' => '1']);

        self::assertTrue($this->reread()->categorySorting->overrideProvider);

        self::assertCount(1, $this->queued());
    }

    /**
     * Pressing the option that is already chosen costs nothing.
     *
     * Not a micro-optimisation: these controls submit on change, and a mailbox
     * re-file is minutes of a worker. Dispatching on every save would let a
     * distracted click queue that work to write the same answers back.
     */
    public function testPressingTheOptionAlreadyChosenDispatchesNothing(): void
    {
        [$client] = $this->signedIn();

        $this->post($client, ['source' => CategorySource::Rules->value]);

        self::assertResponseRedirects();
        self::assertCount(0, $this->queued());
    }

    /**
     * A value from nowhere sorts mail the ordinary way rather than 500ing.
     *
     * The field is a radio group, so anything else arrived by hand — and the
     * charitable reading is the one that leaves somebody with a working inbox.
     */
    public function testAnUnknownSourceFallsBackToTheRules(): void
    {
        [$client] = $this->signedIn();

        $this->post($client, ['source' => 'astrology']);

        self::assertSame(CategorySource::Rules->value, $this->reread()->categorySorting->source);
    }

    /**
     * Asking again queues the work, clamped to what one press may cost.
     *
     * The ceiling is the point of the test. Five hundred model calls is the
     * better part of an hour on a host everybody shares, and the field is a
     * `<select>` — so anything else arrived by hand, and the charitable reading
     * is the one that does not let a hand-edited request spend the afternoon.
     */
    public function testAskingAgainQueuesABoundedRun(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', '/settings/sorting/again', [
            '_token' => $this->token($client),
            'limit'  => '99999',
        ]);

        self::assertResponseRedirects();

        $queued = $this->queued(ReclassifyRecentMessage::class);

        self::assertCount(1, $queued);
        self::assertSame(500, $queued[0]->limit, 'clamped to the ceiling, not taken at face value');
    }

    /** And it is refused without a token, like everything else on this card. */
    public function testAskingAgainWithoutATokenIsRefused(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', '/settings/sorting/again', ['limit' => '200']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertCount(0, $this->queued(ReclassifyRecentMessage::class));
    }

    public function testAPostWithoutATokenIsRefused(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', '/settings/sorting', ['source' => CategorySource::Assistant->value]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertCount(0, $this->queued());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @param array<string, string> $fields */
    private function post(KernelBrowser $client, array $fields): void
    {
        $client->request('POST', '/settings/sorting', [
            '_token' => $this->token($client),
            ...$fields,
        ]);
    }

    /**
     * The row as it stands now.
     *
     * Re-read rather than refreshed: the request cycle leaves the object made
     * in the fixture detached, and refresh() on a detached entity is an error
     * rather than a re-read.
     */
    private function reread(): User
    {
        $this->em->clear();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);

        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * @param class-string $type
     *
     * @return list<object>
     */
    private function queued(string $type = ResortMailboxMessage::class): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.maintenance');

        return array_values(array_filter(
            array_map(static fn (object $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof $type,
        ));
    }

    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings?section=general');

        return (string) $crawler
            ->filter('form[action="/settings/sorting"] input[name="_token"]')
            ->first()
            ->attr('value');
    }

    /** @return array{KernelBrowser} */
    private function signedIn(): array
    {
        $user            = new User();
        $user->email     = self::EMAIL;
        $user->nameFirst = 'Sorting';
        $user->nameLast  = 'Preference';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return [$this->client];
    }
}
