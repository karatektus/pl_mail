<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\InsightReportController;
use App\Entity\Insight\InsightReport;
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

        $client->request('POST', self::PANEL . '/' . $report->id . '/handled', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', self::PANEL . '/' . $report->id . '/delete', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', self::PANEL . '/clear-handled', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(1, $this->rowsWithId($report->id), 'a refused request deleted the report anyway');
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
            '/' . $report->id . '/handled',
            '/' . $report->id . '/delete',
            '/clear-handled',
        ];

        foreach ($paths as $path) {
            $client->request('POST', self::PANEL . $path, ['_token' => 'nonsense', 'scope' => 'all']);

            self::assertResponseStatusCodeSame(403, $path . ' ran without a CSRF token');
        }

        self::assertSame(1, $this->rowsWithId($report->id));
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
            'attachment; filename=plmail-insight-reports-',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        // A proxy holding a copy of somebody's mail is the whole feature undone.
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($document);
        self::assertSame(InsightReportController::DOCUMENT_FORMAT, $document['format']);
        self::assertSame(InsightReportController::DOCUMENT_VERSION, $document['version']);
        self::assertSame('pending', $document['scope'], 'the default export was not the pile with work left in it');
        self::assertArrayHasKey('exportedAt', $document);
        self::assertSame(count($document['reports']), $document['count'], 'the header disagrees with the records');

        $record = $this->recordFor($document, (int) $report->id);

        self::assertSame($report->subject, $record['subject']);
        self::assertSame($report->fromAddress, $record['fromAddress']);
        self::assertSame($report->fromName, $record['fromName']);
        self::assertSame($report->note, $record['note']);
        self::assertSame($report->bodyText, $record['bodyText'], 'the body is the corpus; a record without it is a report of a report');
        self::assertNotNull($record['receivedAt']);
        self::assertNull($record['handledAt']);

        // The decision, asserted: downloading is not processing.
        self::assertNull($this->handledAt($report->id), 'the export marked the report handled behind the admin\'s back');
    }

    /**
     * The other scope, and the one that proves the default was doing something:
     * a handled report is absent by default and present when asked for.
     */
    public function testTheAllScopeIncludesWhatTheDefaultLeavesOut(): void
    {
        $client = $this->signInAsAdmin();
        $done   = $this->seedReport('already dealt with', handled: true);

        $client->request('POST', self::PANEL . '/export', [
            '_token' => $this->token($client, 'insight_reports_export'),
        ]);

        self::assertStringNotContainsString((string) $done->subject, (string) $client->getResponse()->getContent());

        $client->request('POST', self::PANEL . '/export', [
            '_token' => $this->token($client, 'insight_reports_export'),
            'scope'  => 'all',
        ]);

        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('all', $document['scope']);
        self::assertNotNull($this->recordFor($document, (int) $done->id)['handledAt']);
    }

    public function testMarkingHandledIsIdempotent(): void
    {
        $client = $this->signInAsAdmin();
        $report = $this->seedReport('clicked twice');

        $client->request('POST', self::PANEL . '/' . $report->id . '/handled', [
            '_token' => $this->token($client, 'insight_report_handled_' . $report->id),
        ]);

        self::assertResponseIsSuccessful();

        $first = $this->handledAt($report->id);
        self::assertNotNull($first, 'the report was not marked handled at all');

        $client->request('POST', self::PANEL . '/' . $report->id . '/handled', [
            '_token' => $this->token($client, 'insight_report_handled_' . $report->id),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($first, $this->handledAt($report->id), 'the second click moved the stamp');
    }

    public function testDeletingRemovesTheRow(): void
    {
        $client = $this->signInAsAdmin();
        $report = $this->seedReport('a mis-click');
        $id     = (int) $report->id;

        $client->request('POST', self::PANEL . '/' . $id . '/delete', [
            '_token' => $this->token($client, 'insight_report_delete_' . $id),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->rowsWithId($id));
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
        self::assertSame(0, $this->rowsWithId($done->id), 'the handled report survived the sweep');
        self::assertSame(1, $this->rowsWithId($waiting->id), 'the sweep took a report nobody had finished with');
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
    private function recordFor(array $document, int $id): array
    {
        foreach ($document['reports'] as $record) {
            if ($id === $record['id']) {
                return $record;
            }
        }

        self::fail(sprintf('report %d is not in the export', $id));
    }

    /** Read past the ORM's identity map: what is actually in the row now. */
    private function handledAt(?int $id): ?string
    {
        $value = $this->connection->fetchOne('SELECT handled_at FROM insight_report WHERE id = ?', [$id]);

        return false === $value || null === $value ? null : (string) $value;
    }

    private function rowsWithId(?int $id): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM insight_report WHERE id = ?', [$id]);
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
