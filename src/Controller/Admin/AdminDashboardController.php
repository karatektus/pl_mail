<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Repository\Insight\InsightReportRepository;
use App\Repository\Monitoring\ClientErrorRepository;
use App\Repository\Monitoring\LogEntryRepository;
use App\Repository\Monitoring\LogSettingsRepository;
use App\Repository\Monitoring\PostgresStatusRepository;
use App\Service\Monitoring\AdminMonitoringService;
use App\Service\Monitoring\DbPerformanceService;
use App\Service\Monitoring\LogLevelResolver;
use App\Service\Monitoring\QueueMonitor;
use App\Service\Monitoring\WorkerRestartSignal;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin', name: 'app_admin_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminDashboardController extends AbstractController
{
    use ChecksCsrf;

    private const array SECTIONS = ['system', 'database', 'logs', 'insight-reports', 'integrations', 'push', 'ai', 'users', 'backup', 'reset'];
    private const int LOGS_PER_PAGE = 100;

    /**
     * One page of the queue backlog. Small on purpose: the panel scrolls
     * inside a fixed height and fetches the next page as that scroll reaches
     * the end, so the first paint stays cheap however long the queue is.
     */
    private const int QUEUE_PER_PAGE = 25;

    /** Monolog numeric levels offered as minimum-level filters. */
    private const array LOG_LEVELS = [
        200 => 'info',
        250 => 'notice',
        300 => 'warning',
        400 => 'error',
        500 => 'critical',
    ];

    public function __construct(
        private readonly LogSettingsRepository  $logSettings,
        private readonly LogLevelResolver       $logLevels,
        private readonly AdminMonitoringService $monitoring,
        private readonly QueueMonitor           $queueMonitor,
        private readonly LogEntryRepository     $logEntryRepository,
        private readonly ClientErrorRepository  $clientErrors,
        private readonly DbPerformanceService   $dbPerformance,
        private readonly WorkerRestartSignal    $restartSignal,
        private readonly PostgresStatusRepository $statistics,
        private readonly EntityManagerInterface $entityManager,
        private readonly InsightReportRepository $insightReports,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'dashboard')]
    public function index(Request $request): Response
    {
        $section = (string) $request->query->get('section', 'system');

        if (false === in_array($section, self::SECTIONS, true)) {
            $section = 'system';
        }

        return $this->render('admin/index.html.twig', [
            'section'               => $section,
            // The nav badge, counted here rather than inside the reported-mail
            // frame: the badge has to be right on every section, and the frame
            // that knows the number is the one section that is not loaded.
            // One indexed count on a page that already runs several.
            'pendingInsightReports' => $this->insightReports->countPending(),
        ]);
    }

    /**
     * Auto-refreshed live panels. Loaded (and re-loaded) as a Turbo Frame.
     */
    #[Route('/live', name: 'live')]
    public function live(): Response
    {
        $restartRequestedAt = $this->restartSignal->requestedAt();

        return $this->render('admin/_live_frame.html.twig', [
            // Seconds since a restart was asked for, or null. The Processes
            // card needs it because a restart clears heartbeat rows instead of
            // reddening them, so rows vanishing is expected, not an outage.
            'restartRequestedAgo' => null === $restartRequestedAt
                ? null
                : (int) (microtime(true) - $restartRequestedAt),
            'collapsedPanels' => $this->collapsedPanels(),
            'heartbeats'      => $this->monitoring->heartbeats(),
            'webhooks'        => $this->monitoring->gmailWebhooks(),
            'pushDiagnostics' => $this->monitoring->gmailPushDiagnostics(),
            'tokenHealth'     => $this->monitoring->tokenHealth(),
            'queueStats'      => $this->queueMonitor->queueStats(),
            'runningMessages' => $this->queueMonitor->runningMessages(),
            'waitingMessages' => $this->queueMonitor->waitingMessages(self::QUEUE_PER_PAGE),
            'waitingTotal'    => $this->queueMonitor->countWaiting(),
            'queuePerPage'    => self::QUEUE_PER_PAGE,
            'failedMessages'  => $this->queueMonitor->failedMessages(),
            'accountOverview' => $this->monitoring->accountOverview(),
            'tableSizes'      => $this->monitoring->tableSizes(),
        ]);
    }

    /**
     * One page of the queue backlog, filtered.
     *
     * Its own endpoint rather than a slice of /admin/live: searching and
     * paging a queue is a conversation with the database, and re-rendering
     * every other live panel per keystroke would be an odd way to have it.
     * The filter runs over the whole queue — see MessengerQueueRepository.
     */
    #[Route('/queues/waiting', name: 'queue_waiting')]
    public function queueWaiting(Request $request): Response
    {
        $filter = trim((string) $request->query->get('q', ''));
        $offset = max(0, $request->query->getInt('offset'));

        return $this->render('admin/_queue_messages.html.twig', [
            'messages'     => $this->queueMonitor->waitingMessages(self::QUEUE_PER_PAGE, $offset, $filter),
            'waitingTotal' => $this->queueMonitor->countWaiting($filter),
            'offset'       => $offset,
            'queuePerPage' => self::QUEUE_PER_PAGE,
        ]);
    }

    /**
     * Filterable log browser. Its own frame, not auto-refreshed, so reading
     * a stack trace doesn't get yanked away mid-scroll.
     */
    #[Route('/logs', name: 'logs')]
    public function logs(Request $request): Response
    {
        $minLevel = (int) $request->query->get('level', 300);

        if (false === array_key_exists($minLevel, self::LOG_LEVELS)) {
            $minLevel = 300;
        }

        $channel = trim((string) $request->query->get('channel', ''));
        $page    = max(1, (int) $request->query->get('page', 1));
        $offset  = ($page - 1) * self::LOGS_PER_PAGE;

        $entries = $this->logEntryRepository->search($minLevel, $channel, self::LOGS_PER_PAGE, $offset);
        $total   = $this->logEntryRepository->countSearch($minLevel, $channel);

        // Opening the browser is what "seen" means, and the mark is set from
        // the moment it was opened rather than from the newest entry on
        // screen: anything logged while it is open is genuinely unread, and
        // the outline should come back for it.
        /** @var User $user */
        $user = $this->getUser();
        $user->logsSeenAt = new DateTimeImmutable();
        $this->entityManager->flush();

        return $this->render('admin/_logs_frame.html.twig', [
            'entries'  => $entries,
            'total'    => $total,
            'page'     => $page,
            'pages'    => max(1, (int) ceil($total / self::LOGS_PER_PAGE)),
            'minLevel' => $minLevel,
            'channel'  => $channel,
            'levels'   => self::LOG_LEVELS,
            'channels' => $this->logEntryRepository->distinctChannels(),
            // What is KEPT, as opposed to what is shown above it. Null when the
            // install has never chosen and is following APP_DB_LOG_LEVEL, which
            // the template renders as the environment's own value so the page
            // says what is actually happening either way.
            'capture'    => $this->logSettings->current()?->minimumLevel,
            'captureEnv' => $this->logLevels->level()->toPsrLogLevel(),

            // The browser's own faults, in their own card below the log. They
            // are a different kind of thing from a server error — a population
            // with a count and a lifetime rather than one event in one request
            // — and mixing them puts a list nobody can read past in front of
            // the log somebody actually came here to read.
            'clientErrors'      => $this->clientErrors->recent(),
            'clientErrorsTotal' => $this->clientErrors->countAll(),
        ]);
    }

    /**
     * Deletes the entries the log browser is currently showing — same filter,
     * so what disappears is what was on screen.
     */
    #[Route('/logs/clear', name: 'logs_clear', methods: ['POST'])]
    public function clearLogs(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_logs_clear');

        $minLevel = (int) $request->request->get('level', 300);

        if (false === array_key_exists($minLevel, self::LOG_LEVELS)) {
            $minLevel = 300;
        }

        $this->logEntryRepository->deleteSearch(
            $minLevel,
            trim((string) $request->request->get('channel', '')),
        );

        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'logs']);
    }

    /**
     * Empties the browser-error card.
     *
     * All of it, unlike the log's own clear button, which takes only what the
     * current filter shows. There is no filter here and there does not need to
     * be: the card holds distinct faults rather than occurrences, so it is a
     * short list by construction, and what an administrator does with it is
     * read it, fix something, and clear it to see whether the fixed one comes
     * back.
     */
    #[Route('/client-errors/clear', name: 'client_errors_clear', methods: ['POST'])]
    public function clearClientErrors(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_client_errors_clear');

        $this->clientErrors->clearAll();

        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'logs']);
    }

    /**
     * How much is kept, as opposed to how much is shown.
     *
     * The select beside this one filters the browser; this sets the level at
     * which entries reach the table at all, which is the setting that used to
     * mean editing `APP_DB_LOG_LEVEL` on the host and restarting the stack.
     * That is a poor thing to have to do at the moment it is wanted — something
     * is wrong and the answer is one level further down — and it is why installs
     * end up running on `info` for months.
     *
     * An empty value clears the row's level rather than storing the
     * environment's current one, so "follow the configuration" stays reachable
     * after a choice has been made. See LogSettings.
     */
    #[Route('/logs/level', name: 'logs_level', methods: ['POST'])]
    public function setLogLevel(
        Request                $request,
        LogSettingsRepository  $settings,
        LogLevelResolver       $levels,
    ): Response {
        $this->assertCsrf($request, 'admin_logs_level');

        $chosen = trim((string) $request->request->get('capture', ''));

        // The closed set is the browser's own, minus nothing: a level worth
        // filtering by is a level worth capturing. Anything else — including
        // the empty string the "follow the environment" option submits — stores
        // null, which is the documented way back.
        $names = array_values(self::LOG_LEVELS);

        $stored = $settings->currentOrNew();
        $stored->minimumLevel = in_array($chosen, $names, true) ? $chosen : null;

        $this->entityManager->persist($stored);
        $this->entityManager->flush();

        // This process obeys immediately; the others catch up within the
        // resolver's TTL. Without it the admin changes the level, reloads, and
        // is told it is still the old one by the very handler they just
        // configured.
        $levels->forget();

        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'logs']);
    }

    #[Route('/db', name: 'db')]
    public function db(): Response
    {
        return $this->render('admin/_db_frame.html.twig', [
            'collapsedPanels'         => $this->collapsedPanels(),
            'statStatementsAvailable' => $this->dbPerformance->isStatStatementsAvailable(),
            'statStatementsPossible'  => $this->statistics->canCollectStatements(),
            'slowestByMean'           => $this->dbPerformance->slowestByMean(),
            'heaviestByTotal'         => $this->dbPerformance->heaviestByTotal(),
            'activeQueries'           => $this->dbPerformance->activeQueries(),
            'gauges'                  => $this->dbPerformance->healthGauges(),
        ]);
    }

    #[Route('/db/reset-stats', name: 'db_reset', methods: ['POST'])]
    public function resetDbStats(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_db_reset');

        $this->dbPerformance->resetStatStatements();

        return $this->redirectToRoute('app_admin_dashboard');
    }

    /**
     * Turn statement collection on from the panel that reports it missing.
     *
     * Normally unreachable: app:db:migrate enables the extension at boot, so
     * the button this posts from only renders where that was refused — a role
     * without rights to CREATE EXTENSION, most likely. Worth having anyway,
     * because the alternative is a shell on somebody's database server.
     */
    #[Route('/db/stat-statements/enable', name: 'db_stat_statements_enable', methods: ['POST'])]
    public function enableStatStatements(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_db_stat_statements_enable');

        if (false === $this->statistics->enableStatStatements()) {
            $this->addFlash('error', $this->translator->trans('admin.db.extension_enable_failed'));
        }

        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'database']);
    }

    #[Route('/failed/{id}/retry', name: 'failed_retry', methods: ['POST'])]
    public function retryFailed(Request $request, string $id): Response
    {
        $this->assertCsrf($request, 'admin_failed_' . $id);

        $this->queueMonitor->retry($id);

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/failed/{id}/delete', name: 'failed_delete', methods: ['POST'])]
    public function deleteFailed(Request $request, string $id): Response
    {
        $this->assertCsrf($request, 'admin_failed_' . $id);

        $this->queueMonitor->remove($id);

        return $this->redirectToRoute('app_admin_dashboard');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Panels this user has collapsed. Rendered server-side rather than
     * restored by JavaScript, so the page never flashes every panel open
     * before collapsing them.
     *
     * @return list<string>
     */
    private function collapsedPanels(): array
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user->collapsedAdminPanels;
    }

}
