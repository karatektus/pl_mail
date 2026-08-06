<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\PushDeliveryOutcome;
use App\Domain\Enum\PushTransport;
use App\Entity\Push\PushDelivery;
use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What a user is told about their own devices, which is the half of the
 * delivery log they can see.
 *
 * The claim worth pinning is the pairing: a device row is only useful if the
 * subscription and its last delivery are joined up, and they are joined on the
 * client-chosen device id rather than by a relation — the log deliberately has
 * no foreign key to the subscription, so that a row survives the endpoint being
 * retired. A join written the obvious way instead would leave every line saying
 * "nothing sent yet".
 *
 * The other claim is the empty one: a registered device with no deliveries says
 * so, rather than being omitted or showing a blank. On a quiet mailbox that is
 * the normal state for hours, and a line that looked broken would send people
 * to toggle notifications off and on for no reason.
 *
 * Skips itself without the seeded admin, as the other controller tests do.
 */
final class PushDeviceListTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

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

    public function testADeviceIsShownWithItsTransportVerifiedStateAndLastDelivery(): void
    {
        $this->subscribe('a-phone', verified: true);
        $this->deliver('a-phone', PushDeliveryOutcome::Accepted);

        $text = $this->notificationsPane();

        self::assertStringContainsString('a-phone', $text);
        self::assertStringContainsString('Firebase', $text, 'which transport decides what to check next');
        self::assertStringContainsString('Verified', $text);
        self::assertStringContainsString('Delivered', $text);
    }

    public function testADeviceThatHasBeenSentNothingSaysSoRatherThanLookingBroken(): void
    {
        $this->subscribe('a-quiet-phone', verified: true);

        $text = $this->notificationsPane();

        self::assertStringContainsString('a-quiet-phone', $text);
        self::assertStringContainsString('Nothing sent yet', $text);
    }

    /**
     * An unverified device receives nothing by design (RFC 8620 §7.2.2), which
     * looks exactly like a broken one — so the state is named on the row.
     */
    public function testAnUnverifiedDeviceIsNamedAsSuchRatherThanReadingAsAFailure(): void
    {
        $this->subscribe('a-new-phone', verified: false);
        $this->deliver('a-new-phone', PushDeliveryOutcome::Accepted);

        self::assertStringContainsString('Not verified', $this->notificationsPane());
    }

    private function notificationsPane(): string
    {
        $crawler = $this->client->request('GET', '/settings?section=notifications');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler->filter('body')->text();
    }

    private function subscribe(string $deviceClientId, bool $verified): void
    {
        $subscription = PushSubscription::fcm($this->user, $deviceClientId, 'a-device-token');

        if (true === $verified) {
            $subscription->verify((string) $subscription->verificationCode);
        }

        $this->em->persist($subscription);
        $this->em->flush();
    }

    private function deliver(string $deviceClientId, PushDeliveryOutcome $outcome): void
    {
        $this->em->persist(new PushDelivery(
            $this->user,
            $deviceClientId,
            PushTransport::Fcm,
            'StateChange',
            $outcome,
            'HTTP 200',
            42,
        ));

        $this->em->flush();
    }
}
