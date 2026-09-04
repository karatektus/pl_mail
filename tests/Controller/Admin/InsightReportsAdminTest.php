<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\InsightReportController;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Insight\InsightReport;
use App\Entity\Monitoring\CategoryReport;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The five doors on the reported-mail panel.
 *
 * The claim: this section hands out other people's mail, so nobody but an admin
 * with a token gets through any of them — and the two writes behind them do
 * exactly what the panel says and no more.
 *
 * Three of the assertions here guard a specific way this could go wrong.
 *
 * The security case is the export. It is the one response in the app that
 * contains the sender, subject and body of mail belonging to every user who
 * ever pressed the report button; a 403 that had degraded to a redirect, or a
 * route that had quietly lost its #[IsGranted], would hand that to any signed-in
 * account. It is asserted per route rather than once, because a whitelist is
 * only ever wrong about one entry.
 *
 * The second is that exporting does not stamp handledAt. That is a decision
 * (see the controller's docblock: downloading is not processing) and it is one
 * line away from being "conveniently" reversed — after which the second export
 * of a pile comes back empty and the reports look done because somebody
 * downloaded them.
 *
 * The third is idempotence on "mark handled". A second click that moved the
 * stamp forward would still look right on screen and would silently make the
 * timestamp mean "when it was last clicked", which is not what anything reads
 * it for.
 *
 * Everything runs inside a transaction that is rolled back, so the suite's
 * shared database is left as it was found — which also makes the clear-handled
 * test safe, since that action really does delete every handled row there is.
 */
final class InsightReportsAdminTest extends WebTestCase
{
    private const string PANEL = '/admin/insight-reports';

    private EntityManagerInterface $em;

    private Connection $connection;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnOrdinaryUserIsRefusedAtEveryDoor(): void
    {
        $client = $this->boot();
        $client->loginUser($this->seedUser());

        $report = $this->seedReport('locked out');

        $client->request('GET', self::PANEL);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', self::PANEL . '/export', ['_token' => 'irrelevant', 'scope' => 'all']);
        self::assertResponseStatusCodeSame(403, 'a non-admin was handed other people\'s mail');

        $client->request('POST', self::PANEL . '/insight/' . $report->id . '/handled', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', self::PANEL . '/insight/' . $report->id . '/delete', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', self::PANEL . '/clear-handled', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(1, $this->rowsWithId('insight_report', (int) $report->id), 'a refused request deleted the report anyway');
    }

    /**
     * A signed-in admin is not enough. Without this, any page an admin visits
     * could make their browser fetch the whole pile of reported mail — or empty
     * it.
     */
    public function testATokenlessPostIsRefusedOnEveryAction(): void
    {
        $client = $this->signInAsAdmin();
        $report = $this->seedReport('no token');

        $paths = [
            '/export',
            '/insight/' . $report->id . '/handled',
            '/insight/' . $report->id . '/delete',
            '/clear-handled',
        ];

        foreach ($paths as $path) {
            $client->request('POST', self::PANEL . $path, ['_token' => 'nonsense', 'scope' => 'all']);

            self::assertResponseStatusCodeSame(403, $path . ' ran without a CSRF token');
        }

        self::assertSame(1, $this->rowsWithId('insight_report', (int) $report->id));
    }

    public function testThePanelListsAReportWithItsSenderSubjectAndNote(): void
    {
        $client = $this->signInAsAdmin();
        $report = $this->seedReport('on the panel');

        $client->request('GET', self::PANEL);

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString((string) $report->subject, $body);
        self::assertStringContainsString((string) $report->fromAddress, $body);
        self::assertStringContainsString((string) $report->note, $body, 'the reporter\'s own words are the point');
    }

    /**
     * The clear-handled card appears only when it has something to clear, and
     * says how much that is.
     *
     * Both halves matter and neither is cosmetic. A tinted, irreversible
     * button rendered over an empty pile is furniture that trains an admin to
     * click past the tint; a confirm that names no number asks them to take
     * the panel's word for what is about to be deleted, and what is deleted
     * here is the only surviving copy of mail somebody reported.
     */
    public function testTheClearCardAppearsOnlyWithSomethingToClearAndNamesHowMuch(): void
    {
        $client = $this->signInAsAdmin();

        $this->seedReport('still waiting');

        $client->request('GET', self::PANEL);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            'insight-reports/clear-handled',
            (string) $client->getResponse()->getContent(),
            'nothing is handled, so there is nothing to offer to clear',
        );

        $this->seedReport('already dealt with', handled: true);

        $client->request('GET', self::PANEL);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('insight-reports/clear-handled', $body);
        // The count reaches the copy. Asserted as the rendered number rather
        // than the placeholder, because a %count% that never got substituted
        // renders perfectly happily and reads as a bug nobody sees.
        self::assertStringNotContainsString('%count%', $body);
        self::assertMatchesRegularExpression('~\\b1\\b~', $body);
    }

    /**
     * The section around the frame: the nav entry resolves, and the badge
     * counts.
     *
     * Worth its own test because the frame is loaded lazily and this page is
     * the only thing that names the route or reads the count — a mistyped route
     * name here is a 500 that the frame's own tests cannot see.
     */
    public function testTheDashboardOpensTheSectionAndBadgesWhatIsWaiting(): void
    {
        $client = $this->signInAsAdmin();
        $this->seedReport('badged');

        $client->request('GET', '/admin?section=insight-reports');

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('/admin/insight-reports', $body, 'the section never loads its frame');
        self::assertMatchesRegularExpression(
            '/aria-label="[^"]*waiting"[^>]*>\s*\d+\s*</',
            $body,
            'nothing told the admin how many reports are waiting',
        );
    }

    public function testTheExportIsAFileCarryingTheReportAndLeavesItUnhandled(): void
    {
        $client = $this->signInAsAdmin();
        $report = $this->seedReport('in the file');

        $client->request('POST', self::PANEL . '/export', [
            '_token' => $this->token($client, 'insight_reports_export'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json; charset=utf-8');
        self::assertStringContainsString(
            'attachment; filename=plmail-reported-mail-',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        // A proxy holding a copy of somebody's mail is the whole feature undone.
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($document);
        self::assertSame(InsightReportController::DOCUMENT_FORMAT, $document['format']);
        self::assertSame(InsightReportController::DOCUMENT_VERSION, $document['version']);
        // Everything, because nothing was ticked. The scope used to be a
        // drop-down and is now the selection, and an empty post is the one a
        // browser without JavaScript sends — a download that answered it with
        // an empty file would be worse than a large one.
        self::assertSame('all', $document['scope'], 'an unticked export did not fall back to everything');
        self::assertArrayHasKey('exportedAt', $document);
        self::assertSame(count($document['reports']), $document['count'], 'the header disagrees with the records');

        $record = $this->recordFor($document, 'insight', (int) $report->id);

        self::assertSame('insight', $record['kind'], 'the record does not say which kind it is');
        self::assertSame($report->subject, $record['subject']);
        self::assertSame($report->fromAddress, $record['fromAddress']);
        self::assertSame($report->fromName, $record['fromName']);
        self::assertSame($report->note, $record['note']);
        self::assertSame($report->bodyText, $record['bodyText'], 'the body is the corpus; a record without it is a report of a report');
        self::assertNotNull($record['receivedAt']);
        self::assertNull($record['handledAt']);

        // The decision, asserted: downloading is not processing.
        self::assertNull($this->handledAt('insight_report', (int) $report->id), 'the export marked the report handled behind the admin\'s back');
    }

    /**
     * The selection is the scope, and it is the point of the rework.
     *
     * A fixed scope — everything, or only the unhandled part — answers the
     * question an admin has on their first visit and none of the ones they have
     * afterwards. The one that comes up is "these, the ones about the shop",
     * and the only way to be sure that works is to ask for one report out of
     * two and check the other is not in the file.
     */
    public function testExportingASelectionTakesOnlyTheTickedReports(): void
    {
        $client  = $this->signInAsAdmin();
        $wanted  = $this->seedReport('asked for');
        $ignored = $this->seedReport('left behind');

        $client->request('POST', self::PANEL . '/export', [
            '_token' => $this->token($client, 'insight_reports_export'),
            'keys'   => ['insight:' . $wanted->id],
        ]);

        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('selection', $document['scope']);
        self::assertSame(1, $document['count'], 'a one-report selection produced a different number of records');
        // recordFor() fails when it is absent, so this asserts the record is
        // the right one rather than merely present.
        self::assertSame($wanted->subject, $this->recordFor($document, 'insight', (int) $wanted->id)['subject']);
        self::assertStringNotContainsString(
            (string) $ignored->subject,
            (string) $client->getResponse()->getContent(),
            'a report nobody ticked was exported anyway',
        );
    }

    /**
     * Both kinds, one pile — the whole claim of the unified panel.
     *
     * They were two tables behind two panels with two export buttons, and the
     * only thing that makes them one feature is that this list and this file
     * hold both. Asserted together because the failure mode is silent: a panel
     * that lists only one kind looks completely normal to anybody who has not
     * just reported the other.
     */
    public function testThePanelAndTheExportHoldBothKindsOfReport(): void
    {
        $client   = $this->signInAsAdmin();
        $insight  = $this->seedReport('a missed insight');
        $category = $this->seedCategoryReport('a wrong tab');

        $client->request('GET', self::PANEL);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString((string) $insight->subject, $body);
        self::assertStringContainsString($category->subject, $body, 'the wrongly-sorted report is not in the list');

        $client->request('POST', self::PANEL . '/export', [
            '_token' => $this->token($client, 'insight_reports_export'),
        ]);

        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $record   = $this->recordFor($document, 'category', (int) $category->id);

        // The evidence, not only the verdicts: these are the fields somebody
        // counts when deciding whether a rule should change.
        self::assertSame('promotions', $record['filed']);
        self::assertSame('primary', $record['shouldBe']);
        self::assertSame('list-unsubscribe', $record['bulkHeaders']);
        self::assertFalse($record['hasPlainText']);
        self::assertStringContainsString('filed:promotions should:primary', $record['line']);

        self::assertSame($insight->subject, $this->recordFor($document, 'insight', (int) $insight->id)['subject']);
    }

    /** A category report can be crossed off too, which is what makes it a worklist. */
    public function testACategoryReportCanBeMarkedHandledAndDeleted(): void
    {
        $client = $this->signInAsAdmin();
        $report = $this->seedCategoryReport('dealt with');
        $id     = (int) $report->id;

        $client->request('POST', self::PANEL . '/category/' . $id . '/handled', [
            '_token' => $this->token($client, 'insight_report_handled_category_' . $id),
        ]);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->handledAt('category_report', $id));

        $client->request('POST', self::PANEL . '/category/' . $id . '/delete', [
            '_token' => $this->token($client, 'insight_report_delete_category_' . $id),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->rowsWithId('category_report', $id));
    }

    public function testMarkingHandledIsIdempotent(): void
    {
        $client = $this->signInAsAdmin();
        $report = $this->seedReport('clicked twice');

        $client->request('POST', self::PANEL . '/insight/' . $report->id . '/handled', [
            '_token' => $this->token($client, 'insight_report_handled_insight_' . $report->id),
        ]);

        self::assertResponseIsSuccessful();

        $first = $this->handledAt('insight_report', (int) $report->id);
        self::assertNotNull($first, 'the report was not marked handled at all');

        $client->request('POST', self::PANEL . '/insight/' . $report->id . '/handled', [
            '_token' => $this->token($client, 'insight_report_handled_insight_' . $report->id),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($first, $this->handledAt('insight_report', (int) $report->id), 'the second click moved the stamp');
    }

    public function testDeletingRemovesTheRow(): void
    {
        $client = $this->signInAsAdmin();
        $report = $this->seedReport('a mis-click');
        $id     = (int) $report->id;

        $client->request('POST', self::PANEL . '/insight/' . $id . '/delete', [
            '_token' => $this->token($client, 'insight_report_delete_insight_' . $id),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->rowsWithId('insight_report', $id));
    }

    /**
     * Clearing takes the handled pile and nothing else. The pending report is
     * seeded alongside precisely so a sweep that had lost its condition fails
     * here rather than in front of an admin.
     */
    public function testClearingHandledLeavesWhatIsStillWaiting(): void
    {
        $client  = $this->signInAsAdmin();
        $done    = $this->seedReport('finished with', handled: true);
        $waiting = $this->seedReport('still waiting');

        $client->request('POST', self::PANEL . '/clear-handled', [
            '_token' => $this->token($client, 'insight_reports_clear_handled'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->rowsWithId('insight_report', (int) $done->id), 'the handled report survived the sweep');
        self::assertSame(1, $this->rowsWithId('insight_report', (int) $waiting->id), 'the sweep took a report nobody had finished with');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * One record out of the exported document, by id.
     *
     * By id rather than by position: the suite's database is shared and may
     * hold reports this test did not write, so a document with three records in
     * it is not a failure — a document missing this one is.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function recordFor(array $document, string $kind, int $id): array
    {
        foreach ($document['reports'] as $record) {
            // Kind AND id: the two tables number themselves independently, so
            // `7` alone matches two different reports.
            if ($id === $record['id'] && $kind === $record['kind']) {
                return $record;
            }
        }

        self::fail(sprintf('%s report %d is not in the export', $kind, $id));
    }

    /**
     * Read past the ORM's identity map: what is actually in the row now.
     *
     * The table is a parameter because both kinds are triaged through the same
     * routes now, and a helper that only ever looked at one of them would pass
     * happily while the other went untouched.
     */
    private function handledAt(string $table, ?int $id): ?string
    {
        $value = $this->connection->fetchOne(
            sprintf('SELECT handled_at FROM %s WHERE id = ?', $table),
            [$id],
        );

        return false === $value || null === $value ? null : (string) $value;
    }

    private function rowsWithId(string $table, ?int $id): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE id = ?', $table),
            [$id],
        );
    }

    /**
     * A wrongly-sorted report, with the evidence columns filled.
     *
     * Deliberately the awkward shape rather than a neutral one: a mailing with
     * no plain-text part, filed by the rules on its unsubscribe header, that a
     * human says belongs in Primary. That is the case the whole feature exists
     * to collect, and a fixture without those columns would let a dropped field
     * pass.
     */
    private function seedCategoryReport(string $marker, bool $handled = false): CategoryReport
    {
        $unique = uniqid('', true);

        $report              = new CategoryReport();
        $report->usr         = $this->seedUser();
        $report->messageId   = random_int(1_000, 9_999);
        $report->filed       = MessageCategory::Promotions;
        $report->shouldBe    = MessageCategory::Primary;
        $report->rules       = MessageCategory::Promotions;
        $report->rulesSignal = 'list-unsubscribe';
        $report->model       = MessageCategory::Primary;
        $report->aiAsked     = true;
        $report->source      = 'assistant';
        $report->bulkHeaders = 'list-unsubscribe';
        $report->listId      = '<jobs.karriere.test>';
        $report->fromAddress = sprintf('anna-%s@karriere.test', $unique);
        $report->fromName    = 'Anna Weber';
        $report->subject     = sprintf('Unterlagen fur die Stelle (%s, %s)', $marker, $unique);

        if (true === $handled) {
            $report->handledAt = new DateTimeImmutable('2026-08-19 18:00:00');
        }

        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }

    /**
     * A report with every snapshot column filled, because the export's job is
     * to carry all of them and a fixture with nulls in it would let a dropped
     * field pass.
     */
    private function seedReport(string $marker, bool $handled = false): InsightReport
    {
        $unique = uniqid('', true);

        $report              = new InsightReport();
        $report->fromAddress = sprintf('sender-%s@carrier.test', $unique);
        $report->fromName    = 'Carrier Notifications';
        $report->subject     = sprintf('Your parcel is on its way (%s, %s)', $marker, $unique);
        $report->receivedAt  = new DateTimeImmutable('2026-08-19 09:15:00');
        $report->bodyText    = "Sendungsnummer: 00340434161094042557\nZustellung am 20.08.2026";
        $report->note        = sprintf('Die Sendungsnummer steht ganz unten (%s)', $unique);
        $report->reportedBy  = $this->seedUser();

        if (true === $handled) {
            $report->handledAt = new DateTimeImmutable('2026-08-19 18:00:00');
        }

        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        // Without this the kernel is rebooted between requests and the new
        // container's connection cannot see the uncommitted work.
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        return $client;
    }

    private function signInAsAdmin(): KernelBrowser
    {
        $client = $this->boot();

        $admin = $this->seedUser();
        $admin->addRole(User::ROLE_ADMIN);
        $this->em->flush();

        $client->loginUser($admin);

        return $client;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'insight-reports-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Fixture';
        $user->nameLast  = 'Person';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * A real CSRF token for $id.
     *
     * The GET first is load-bearing — same trick, and the same reason,
     * AdminDataResetTest records.
     */
    private function token(KernelBrowser $client, string $id): string
    {
        $client->request('GET', self::PANEL);

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return (string) static::getContainer()
                ->get('security.csrf.token_manager')
                ->getToken($id)
                ->getValue();
        } finally {
            $stack->pop();
        }
    }
}
