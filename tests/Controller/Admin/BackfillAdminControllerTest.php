<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\Enum\Job\JobState;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\RunBackfillMessage;
use App\Repository\Maintenance\BackfillRun\BackfillRunRepository;
use App\Repository\User\User\UserRepository;
use App\Service\Maintenance\Backfill\BackfillTasks;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Admin → Backfills from the browser's end: an administrator's page, listing
 * the newest repair first, and Run queues exactly the task that was shown.
 *
 * The runner's own behaviour — once, recorded, never retried — is
 * BackfillRunnerTest's. Here the claim is about the door: nobody but an
 * administrator gets through it, and a name that is not a registered task
 * is refused rather than matched to something close.
 */
final class BackfillAdminControllerTest extends WebTestCase
{
    private Connection $connection;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testOnlyAnAdministratorReachesIt(): void
    {
        $client = $this->signedIn('e2e@plmail.test');

        $client->request('GET', '/admin/backfills');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheNewestTaskIsListedFirstAndShownWhenNoneIsChosen(): void
    {
        $client = $this->signedIn('e2e-admin@plmail.test');
        $newest = array_key_first(static::getContainer()->get(BackfillTasks::class)->all());

        $crawler = $client->request('GET', '/admin/backfills');

        self::assertResponseIsSuccessful();
        self::assertSame($newest, trim($crawler->filter('turbo-frame#admin-backfills nav a .font-mono')->first()->text()));
        self::assertSame($newest, trim($crawler->filter('turbo-frame#admin-backfills h3.font-mono')->text()));
    }

    public function testRunQueuesTheTaskThatWasShown(): void
    {
        $client  = $this->signedIn('e2e-admin@plmail.test');
        $crawler = $client->request('GET', '/admin/backfills?task=recipients');

        $client->request('POST', '/admin/backfills/recipients/run', ['_token' => $this->token($crawler)]);

        self::assertResponseRedirects();

        $run = static::getContainer()->get(BackfillRunRepository::class)->activeForTask('recipients');

        self::assertNotNull($run);
        self::assertSame(JobState::Queued, $run->state);
        self::assertSame('e2e-admin@plmail.test', $run->requestedBy?->email);

        $sent = $this->maintenance()->getSent();

        self::assertCount(1, $sent);
        self::assertInstanceOf(RunBackfillMessage::class, $sent[0]->getMessage());
    }

    public function testRunWithoutTheTokenQueuesNothing(): void
    {
        $client = $this->signedIn('e2e-admin@plmail.test');

        $client->request('POST', '/admin/backfills/recipients/run');

        self::assertResponseStatusCodeSame(403);
        self::assertNull(static::getContainer()->get(BackfillRunRepository::class)->activeForTask('recipients'));
    }

    public function testATaskThatIsNotRegisteredIsRefused(): void
    {
        $client  = $this->signedIn('e2e-admin@plmail.test');
        $crawler = $client->request('GET', '/admin/backfills');

        $client->request('POST', '/admin/backfills/recipient/run', ['_token' => $this->token($crawler)]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->maintenance()->getSent());
    }

    public function testCountSaysHowManyMessagesTheTaskWouldLookAt(): void
    {
        $client  = $this->signedIn('e2e-admin@plmail.test');
        $crawler = $client->request('GET', '/admin/backfills?task=safe-html');

        $crawler = $client->request('POST', '/admin/backfills/safe-html/count', ['_token' => $this->token($crawler)]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#admin-backfills [role="status"]'));
        self::assertCount(0, $this->maintenance()->getSent(), 'counting runs nothing');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function signedIn(string $email): KernelBrowser
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        // Never committed: the kernel is kept between requests below, so the
        // runs a test queues are rolled back with it.
        $client->disableReboot();
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $client->loginUser($user);

        return $client;
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('turbo-frame#admin-backfills input[name="_token"]')->attr('value');
    }

    private function maintenance(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.maintenance');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
