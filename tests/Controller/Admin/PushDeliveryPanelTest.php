<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\Enum\PushDeliveryOutcome;
use App\Domain\Enum\PushTransport;
use App\Entity\Push\PushDelivery;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The delivery panel with something in it, which is the only state its bugs
 * live in.
 *
 * PageRendersTest already asks every admin route for a 200, and on a fresh
 * database this table is empty — so every expression inside the row loop is
 * unreached there. Those expressions are the risky part: the outcome's colour
 * and its label both come from methods on a backed enum, called from Twig, and
 * a template that gets that wrong renders perfectly until the first delivery
 * exists and then 500s the page an admin opened *because* something was wrong.
 *
 * The two empty states are asserted here for the same reason they exist. "No
 * delivery matches this filter" and "nothing has ever been pushed" are opposite
 * diagnoses — the first says the log is working, the second says to go and look
 * at the configuration above — and a panel that prints one message for both
 * answers the easy question and hides the real one.
 *
 * Skips itself without the seeded admin, exactly as PageRendersTest does.
 */
final class PushDeliveryPanelTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->admin = $admin;
        $this->client->loginUser($admin);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testADeliveryRendersWithItsDeviceOutcomeAndDetail(): void
    {
        $this->record('a-phone', PushDeliveryOutcome::SubscriptionDestroyed, 'UNREGISTERED');

        $crawler = $this->client->request('GET', '/admin/push/deliveries');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $text = $crawler->filter('turbo-frame#admin-push-deliveries')->text();

        self::assertStringContainsString('a-phone', $text);
        self::assertStringContainsString('UNREGISTERED', $text, 'the detail is the actionable half of a failure');
        self::assertStringContainsString($this->admin->email, $text);
    }

    public function testFilteringNarrowsTheTableAndSaysSoWhenNothingMatches(): void
    {
        $this->record('a-phone', PushDeliveryOutcome::Accepted, 'HTTP 200');

        $crawler = $this->client->request('GET', '/admin/push/deliveries?outcome=failed');

        $text = $crawler->filter('turbo-frame#admin-push-deliveries')->text();

        self::assertStringNotContainsString('a-phone', $text, 'an accepted delivery is not a failed one');
        self::assertStringContainsString('No delivery matches this filter.', $text);
        self::assertStringNotContainsString(
            'Nothing has been pushed yet',
            $text,
            'the log is working — saying otherwise sends an admin to check a configuration that is fine',
        );
    }

    public function testAnUnparseableFilterFallsBackToTheWholeTableRatherThanFailing(): void
    {
        $this->record('a-phone', PushDeliveryOutcome::Accepted, 'HTTP 200');

        $crawler = $this->client->request('GET', '/admin/push/deliveries?transport=fcmm&outcome=exploded');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'a-phone',
            $crawler->filter('turbo-frame#admin-push-deliveries')->text(),
            'this page is reached by editing a query string; a typo must not hide the data',
        );
    }

    private function record(string $deviceClientId, PushDeliveryOutcome $outcome, ?string $detail): void
    {
        $this->em->persist(new PushDelivery(
            $this->admin,
            $deviceClientId,
            PushTransport::Fcm,
            'StateChange',
            $outcome,
            $detail,
            42,
        ));

        $this->em->flush();
    }
}
