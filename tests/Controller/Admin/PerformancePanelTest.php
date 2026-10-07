<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin → Performance, and the one panel it opened with.
 *
 * The summary line it replaces said "typically 6 s, 95% within 13 s" — enough
 * to see that one message took twice as long as the rest, and nothing about
 * why. What is pinned here is that the "why" is on the page: each held message
 * is a row, the slow one says the model had to be loaded, and the row says
 * nothing about what was in the mail.
 */
final class PerformancePanelTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->admin = $admin;
        $this->client->loginUser($admin);

        $this->connection->beginTransaction();
        // Other tests' held mail would be in the table too; this one wants to
        // count its own.
        $this->connection->executeStatement('UPDATE message SET category_held_at = NULL, category_released_at = NULL');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheSectionIsInTheNavigationDirectlyBelowSystem(): void
    {
        $crawler = $this->client->request('GET', '/admin?section=performance');

        self::assertResponseIsSuccessful();

        $links = $crawler->filter('nav a')->each(static fn ($a): string => (string) $a->attr('href'));
        $system = array_search('/admin?section=system', $links, true);

        self::assertNotFalse($system);
        self::assertSame('/admin?section=performance', $links[$system + 1] ?? null);
        self::assertCount(1, $crawler->filter('turbo-frame#admin-performance[src="/admin/performance"]'));
    }

    public function testEachHeldMessageIsARowThatSaysWhereItsWaitWent(): void
    {
        $account = $this->account();

        // Two seconds, a warm model.
        $this->held($account, 'Quarterly numbers — confidential', heldAgo: 600, shownAfter: 2, answeredAfter: 2, queueMs: 300, callMs: 1700, loadMs: 12);
        // Thirteen, eleven of them the model being loaded.
        $this->held($account, 'Salary review', heldAgo: 300, shownAfter: 13, answeredAfter: 13, queueMs: 400, callMs: 12600, loadMs: 11000);
        // The wait ran out; the answer came afterwards and moved it.
        $this->held($account, 'Lab results', heldAgo: 120, shownAfter: 60, answeredAfter: 75, queueMs: 500, callMs: 74000, loadMs: 20);

        $crawler = $this->client->request('GET', '/admin/performance?window=day');

        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('[data-testid="performance-hold"] tbody tr');

        self::assertCount(3, $rows);

        // Newest first.
        self::assertStringContainsString('60.0 s', $rows->eq(0)->text());
        self::assertStringContainsString('Shown first, moved when the answer came', $rows->eq(0)->text());

        self::assertStringContainsString('13.0 s', $rows->eq(1)->text());
        self::assertStringContainsString('11.0 s', $rows->eq(1)->text(), 'the load time is what explains the slow one');
        self::assertStringContainsString('the model had to be loaded first', $rows->eq(1)->text());

        self::assertStringContainsString('2.0 s', $rows->eq(2)->text());
        self::assertStringContainsString('300 ms', $rows->eq(2)->text());
        self::assertStringContainsString('Sorted, then shown', $rows->eq(2)->text());

        // The summary is of the same three.
        $panel = $crawler->filter('[data-testid="performance-hold"]')->text();

        self::assertStringContainsString('Longest', $panel);
        self::assertStringContainsString($account->email, $panel);
    }

    /**
     * An administrator manages every mailbox on the installation and reads
     * none of them.
     */
    public function testNothingAboutWhatWasInTheMailIsShown(): void
    {
        $this->held($this->account(), 'Salary review', heldAgo: 300, shownAfter: 3, answeredAfter: 3, queueMs: 100, callMs: 2900, loadMs: 0);

        $this->client->request('GET', '/admin/performance');

        self::assertResponseIsSuccessful();

        $page = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('Salary review', $page);
        self::assertStringNotContainsString('somebody@elsewhere.test', $page);
    }

    /**
     * The number that says whether push is working. Mail that arrived counts;
     * a mailbox being imported — years of history stored today — does not, and
     * neither does the account's own outgoing copy.
     */
    public function testArrivalLagCountsMailThatArrivedAndNotMailThatWasImported(): void
    {
        $account = $this->account();

        $this->arrived($account, 'somebody@elsewhere.test', receivedAgo: 40);
        $this->arrived($account, 'somebody@elsewhere.test', receivedAgo: 60 * 60 * 24 * 400);
        $this->arrived($account, (string) $account->email, receivedAgo: 5);

        $crawler = $this->client->request('GET', '/admin/performance?window=day');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter('[data-testid="performance-lag"] tbody tr')
            ->reduce(static fn ($tr): bool => str_contains($tr->text(), (string) $account->email));

        self::assertCount(1, $row);

        $cells = $row->filter('td')->each(static fn ($td): string => trim($td->text()));

        self::assertSame('IMAP', $cells[1]);
        self::assertSame('1', $cells[2], 'one message arrived; the import and the sent copy are not arrivals');
        self::assertMatchesRegularExpression('/^(39|40|41|42) s$/', $cells[3]);
    }

    /**
     * By worker, with a worker's queues in the order it looks at them, and
     * without the scheduler — nothing waits for a schedule.
     */
    public function testEveryWorkerIsListedWithTheQueuesItOwns(): void
    {
        $crawler = $this->client->request('GET', '/admin/performance');

        self::assertResponseIsSuccessful();

        $text = $crawler->filter('[data-testid="performance-workers"] tbody')->text();

        foreach (['worker-ingest', 'worker-live', 'worker-release', 'worker-enrich', 'enrich_live', 'enrich_backlog'] as $name) {
            self::assertStringContainsString($name, $text);
        }

        self::assertLessThan(strpos($text, 'enrich_backlog'), strpos($text, 'worker-enrich'));
        self::assertStringNotContainsString('scheduler', $text);
    }

    /**
     * Moved from Admin → AI. The card is fetched by the page, so all this
     * frame carries is the request for the right half.
     */
    public function testTheModelHostCardIsAskedForHereAndNotUnderAi(): void
    {
        $crawler = $this->client->request('GET', '/admin/performance');

        self::assertSame('calls', $crawler->filter('[data-testid="performance-model"]')->attr('data-admin--ai-status-part-value'));

        $ai = $this->client->request('GET', '/admin/ai');

        self::assertSame('backfill', $ai->filter('[data-controller="admin--ai-status"]')->attr('data-admin--ai-status-part-value'));
    }

    public function testAnEmptyPeriodSaysSoRatherThanDrawingAnEmptyTable(): void
    {
        $crawler = $this->client->request('GET', '/admin/performance?window=hour');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="performance-hold"] table'));
        self::assertStringContainsString('No mail was held in this period.', $crawler->filter('[data-testid="performance-hold"]')->text());
    }

    private function held(
        Account $account,
        string $subject,
        int $heldAgo,
        int $shownAfter,
        int $answeredAfter,
        int $queueMs,
        int $callMs,
        int $loadMs,
    ): void {
        $heldAt = new DateTimeImmutable(sprintf('-%d seconds', $heldAgo));

        $message = new Message();
        $message->account = $account;
        $message->subject = $subject;
        $message->fromAddress = 'somebody@elsewhere.test';
        $message->receivedAt = $heldAt;
        $message->hasAttachments = false;
        $message->messageId = sprintf('<performance-%s@example.test>', uniqid('', true));
        $message->categoryHeldAt = $heldAt;
        $message->categoryReleasedAt = $heldAt->modify(sprintf('+%d seconds', $shownAfter));
        $message->aiCategorisedAt = $heldAt->modify(sprintf('+%d seconds', $answeredAfter));
        $message->aiCategory = \App\Domain\Enum\Mail\MessageCategory::Updates;
        $message->category = \App\Domain\Enum\Mail\MessageCategory::Updates;
        $message->aiQueueMs = $queueMs;
        $message->aiCallMs = $callMs;
        $message->aiLoadMs = $loadMs;

        $this->em->persist($message);
        $this->em->flush();
    }

    private function arrived(Account $account, string $from, int $receivedAgo): void
    {
        $message = new Message();
        $message->account = $account;
        $message->subject = 'Arrival fixture';
        $message->fromAddress = $from;
        $message->receivedAt = new DateTimeImmutable(sprintf('-%d seconds', $receivedAgo));
        $message->hasAttachments = false;
        $message->messageId = sprintf('<arrival-%s@example.test>', uniqid('', true));

        $this->em->persist($message);
        $this->em->flush();
    }

    private function account(): Account
    {
        $account = new Account();
        $account->usr = $this->admin;
        $account->email = 'performance-' . bin2hex(random_bytes(4)) . '@example.test';
        $account->username = $account->email;
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
