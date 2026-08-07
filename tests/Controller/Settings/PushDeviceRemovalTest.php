<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\PushDeliveryOutcome;
use App\Domain\Enum\PushTransport;
use App\Entity\Push\PushDelivery;
use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Repository\User\PushSubscriptionRepository;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Removing a registered device from settings.
 *
 * The case this exists for is a subscription that is working perfectly and
 * should not be: an old build of an app registered under a device id nothing
 * uses any more, delivering a second copy of every notification to the same
 * phone. Nothing about it fails, so no automatic retirement will ever reach
 * it, and before this the only remedy was a DELETE typed into psql.
 *
 * Four claims, and each one is a way this could be wrong rather than absent:
 *
 * - the owner can remove their own device, and it is gone from the list;
 * - somebody else's id cannot be removed by guessing it — the row is looked up
 *   scoped to the authenticated user, so an id that exists but belongs to
 *   another account resolves to nothing at all;
 * - a POST without a valid CSRF token is refused, because a destructive action
 *   reachable by a cross-site form is a way to silence somebody's phone from a
 *   page they merely visited;
 * - **the delivery log survives the removal.** PushDelivery is addressed by
 *   device id and deliberately has no relation to the subscription, so that
 *   the history outlives the row — a `remove` that cascaded would erase the
 *   record of a device at exactly the moment somebody starts wondering what
 *   happened to it. This is the assertion most likely to be broken later by
 *   somebody "tidying up" the entity with a OneToMany and a cascade.
 *
 * Skips itself without the seeded users, as the other controller tests do.
 */
final class PushDeviceRemovalTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string OTHER_EMAIL = 'e2e@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private PushSubscriptionRepository $subscriptions;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container           = static::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->subscriptions = $container->get(PushSubscriptionRepository::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->client->loginUser($user);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheOwnerRemovesTheirOwnDeviceAndItLeavesTheList(): void
    {
        $subscription = $this->subscribe($this->user, 'a-stale-phone');
        $id           = (int) $subscription->id;

        $this->post($id, $this->csrfToken($id));

        self::assertResponseRedirects();

        $this->em->clear();
        self::assertNull($this->subscriptions->find($id), 'the subscription row is gone');

        $crawler = $this->client->followRedirect();
        self::assertStringNotContainsString('a-stale-phone', $crawler->filter('body')->text());
    }

    /**
     * The id is the only handle the page has, and it is a small integer — so
     * it is checked against the owner rather than trusted. Another user's
     * device must be untouchable even by someone who has guessed its id.
     */
    public function testAnotherUsersDeviceCannotBeRemovedByGuessingItsId(): void
    {
        $other = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::OTHER_EMAIL]);

        if (false === $other instanceof User) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $subscription = $this->subscribe($other, 'someone-elses-phone');
        $id           = (int) $subscription->id;

        $this->post($id, $this->csrfToken($id));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNotNull($this->subscriptions->find($id), 'the other account keeps its device');
    }

    public function testAPostWithoutAValidCsrfTokenIsRefused(): void
    {
        $subscription = $this->subscribe($this->user, 'a-phone');
        $id           = (int) $subscription->id;

        $this->post($id, 'not-the-token');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNotNull($this->subscriptions->find($id), 'nothing was destroyed');
    }

    /**
     * The point of the whole log: what happened to a device is still readable
     * after the device is gone.
     */
    public function testTheDeliveryLogSurvivesTheDeviceItDescribes(): void
    {
        $subscription = $this->subscribe($this->user, 'a-noisy-phone');
        $this->deliver('a-noisy-phone');

        $before = $this->deliveryCount('a-noisy-phone');
        self::assertSame(1, $before);

        $this->post((int) $subscription->id, $this->csrfToken((int) $subscription->id));
        self::assertResponseRedirects();

        $this->em->clear();
        self::assertNull($this->subscriptions->find((int) $subscription->id));
        self::assertSame(1, $this->deliveryCount('a-noisy-phone'), 'the log outlives its subscription');
    }

    private function post(int $id, string $token): void
    {
        $this->client->request('POST', '/settings/push-devices/' . $id . '/remove', ['_token' => $token]);
    }

    /**
     * The token manager reads the session off the current request, and there
     * is no current request between two client calls — so the page the form
     * lives on is fetched and its session pushed onto the stack, exactly as
     * AdminDataResetTest does it.
     */
    private function csrfToken(int $id): string
    {
        $this->client->request('GET', '/settings?section=notifications');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($this->client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return static::getContainer()
                ->get('security.csrf.token_manager')
                ->getToken('push-device-remove' . $id)
                ->getValue();
        } finally {
            $stack->pop();
        }
    }

    private function subscribe(User $owner, string $deviceClientId): PushSubscription
    {
        $subscription = PushSubscription::fcm($owner, $deviceClientId, 'a-device-token');
        $subscription->verify((string) $subscription->verificationCode);

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    private function deliver(string $deviceClientId): void
    {
        $this->em->persist(new PushDelivery(
            $this->user,
            $deviceClientId,
            PushTransport::Fcm,
            'StateChange',
            PushDeliveryOutcome::Accepted,
            'HTTP 200',
            42,
        ));

        $this->em->flush();
    }

    private function deliveryCount(string $deviceClientId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM push_delivery WHERE device_client_id = :device',
            ['device' => $deviceClientId],
        );
    }
}
