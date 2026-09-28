<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A drop on Starred stars, and never takes a star away.
 *
 * The row's own star button toggles. A drop is a destination, so a
 * conversation that is already starred when it lands must still be starred
 * afterwards — the one way the two differ, and the one this pins.
 */
final class BulkStarTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Fixtures live in a transaction this test rolls back, and a rebooted
        // kernel takes the connection holding it with them.
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testADropStarsWhatIsNotStarredAndLeavesTheRestStarred(): void
    {
        $plain   = $this->thread('Not starred yet');
        $starred = $this->thread('Starred already');

        $starred->starredAt = new DateTimeImmutable('-1 day');
        $this->em->flush();

        $this->post(['ids' => [$plain->id, $starred->id]]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Read back rather than off the instances held here: the request
        // cleared the entity manager on its way out.
        $this->em->clear();

        self::assertNotNull($this->em->find(MessageThread::class, $plain->id)?->starredAt, 'starred by the drop');
        self::assertNotNull(
            $this->em->find(MessageThread::class, $starred->id)?->starredAt,
            'and not unstarred by it — a drop is not the toggle',
        );
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): void
    {
        // The `ajax` token, read the way the real caller reads it — from the
        // layout's meta tag. See BulkMoveGuardTest::post().
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        $this->client->request(
            'POST',
            '/status/bulk/star',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }
}
