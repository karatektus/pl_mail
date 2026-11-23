<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Job\BackgroundJob;
use App\Entity\Mail\MessageThread;
use App\Infrastructure\Messaging\Handler\RunBulkStatusHandler;
use App\Infrastructure\Messaging\Message\RunBulkStatusMessage;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Select all N" on a search result, from the page to the mail (#711).
 *
 * Reported as "bulk trash not working": search, tick the page, "Select all
 * 1077", the bin button, a toast saying the move had started — and then
 * nothing. The search page never named itself to the toolbar, so the request
 * posted the layout's default scope `*`; the controller accepted it, the worker
 * resolved it to no conversations, and the job finished as Done.
 *
 * Three claims, one per link in that chain: the page names the search, the
 * request carries it onto the job, and the job acts on the results. The last
 * runs the handler directly, in this transaction, because the bug was a job
 * that succeeded at nothing — only looking at the mail afterwards shows it.
 */
final class SearchSelectAllTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // The fixtures live in an open transaction on this connection, and a
        // reboot between requests would take it with them.
        $this->client->disableReboot();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->seedLabel('Trash', LabelRole::Trash);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The toolbar names the search and its query; the frame keeps `*`, which
     * is its sync scope — a list of mail from every mailbox has to refresh
     * when any of them does.
     */
    public function testTheSearchPageNamesItselfToTheToolbar(): void
    {
        $this->thread('Invoice for March');

        $crawler = $this->client->request('GET', '/mail/search?q=' . urlencode('invoice from:sender'));

        self::assertResponseIsSuccessful();

        $toolbar = $crawler->filter('[data-controller~="mail--list-toolbar"]');

        self::assertSame('search', $toolbar->attr('data-mail--list-toolbar-view-scope-value'));
        self::assertSame('invoice from:sender', $toolbar->attr('data-mail--list-toolbar-view-value-value'));
        self::assertSame('*', $crawler->filter('#inbox-list-frame')->attr('data-sync-scope'));
    }

    /** The bug, end to end: the job bins the results, and only the results. */
    public function testBinningEverySearchResultBinsThem(): void
    {
        $march = (int) $this->thread('Invoice for March')->id;
        $april = (int) $this->thread('Invoice for April')->id;
        $lunch = (int) $this->thread('Lunch on Thursday')->id;

        $this->post('trash', ['all' => true, 'scope' => 'search', 'value' => 'invoice']);

        self::assertResponseIsSuccessful();

        $job = $this->em->getRepository(BackgroundJob::class)->findOneBy(['usr' => $this->user]);

        self::assertNotNull($job, 'no job was started');
        self::assertSame(JobKind::Trash, $job->kind);
        self::assertSame('search', $job->view['scope']);
        self::assertSame('invoice', $job->view['value']);

        $jobId = (int) $job->id;

        self::getContainer()->get(RunBulkStatusHandler::class)(new RunBulkStatusMessage($jobId));

        $this->em->clear();

        $done = $this->em->find(BackgroundJob::class, $jobId);

        self::assertSame(JobState::Done, $done?->state, (string) $done?->failureReason);
        self::assertSame(2, $done->total, 'the job resolved the search to the wrong number of conversations');

        self::assertTrue($this->em->find(MessageThread::class, $march)?->binned, 'a search result was left in place');
        self::assertTrue($this->em->find(MessageThread::class, $april)?->binned, 'a search result was left in place');
        self::assertFalse($this->em->find(MessageThread::class, $lunch)?->binned, 'mail the search did not list was binned');
    }

    /**
     * A view the worker cannot resolve is refused when it is asked for, not
     * accepted and run as a job over nothing. `*` is what every list without
     * a scope of its own posts.
     */
    public function testAWholeViewWithNoScopeIsRefusedRatherThanRunOverNothing(): void
    {
        $this->thread('Invoice for March');

        $this->post('trash', ['all' => true, 'scope' => '*', 'value' => '']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull(
            $this->em->getRepository(BackgroundJob::class)->findOneBy(['usr' => $this->user]),
            'a job was started for a view that resolves to nothing',
        );
    }

    /** @param array<string, mixed> $body */
    private function post(string $action, array $body): void
    {
        // The `ajax` token, read the way the real caller reads it — from the
        // layout's meta tag. See BulkMoveGuardTest::post().
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        $this->client->request(
            'POST',
            '/status/bulk/' . $action,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }
}
