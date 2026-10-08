<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Enum\Ai\MetricWindow;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Monitoring\QueueMonitor;
use App\Service\System\Work\BackgroundProcesses;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Performance: how long things take on this installation.
 *
 * A SECTION OF ITS OWN, beside System and not inside it. System answers "is it
 * working" — processes, queues, tokens — and refreshes itself while somebody
 * watches. This answers "how long did it take", which is a question about the
 * past, asked of one thing at a time, and usually asked because a number on
 * some other page looked wrong.
 *
 * Four panels: how late mail arrives, how long work waits for each worker,
 * how long new mail is held for the assistant, and how the model host itself
 * is doing — the last moved here from Admin → AI, where it sat under a form
 * it had nothing to do with.
 *
 * It opened with the third, and that panel is the reason it exists: the line
 * under the hold switch in Admin → AI says "typically 6 s, 95% within 13 s",
 * which is enough to see that one message took twice as long as the rest and
 * nothing at all about why. Here each held message is a row, with its wait
 * split into the three places the time can go.
 *
 * NOTHING HERE READS ANYBODY'S MAIL. A row is an account, a time and some
 * durations. See MessageRepository::holdDetails().
 */
#[IsGranted('ROLE_ADMIN')]
final class PerformanceController extends AbstractController
{
    /** Rows in the per-message table. The summary above it covers the whole window. */
    private const int DETAIL_ROWS = 50;

    /**
     * Rows in the slowest-arrivals table. Fewer than the hold table's: it is
     * the tail that is being looked at, and the tail is short.
     */
    private const int SLOWEST_ROWS = 20;

    public function __construct(
        private readonly MessageRepository    $messages,
        private readonly AiSettingsRepository $settings,
        private readonly QueueMonitor         $queues,
        private readonly BackgroundProcesses  $processes,
    ) {
    }

    #[Route('/admin/performance', name: 'app_admin_performance', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // A week unless asked: trickle mail on a small installation is a few
        // dozen messages a day, and a shorter default is mostly an empty table.
        $window = MetricWindow::tryFrom((string) $request->query->get('window', '')) ?? MetricWindow::Week;
        $since  = $window->since(new DateTimeImmutable());

        $settings = $this->settings->currentOrDefault();

        return $this->render('admin/performance/_frame.html.twig', [
            'window'  => $window,
            'windows' => MetricWindow::cases(),
            // Asked for here rather than fetched by the page: one indexed
            // lookup per account, over mail that is already in the database.
            'lag'     => $this->messages->arrivalLagByAccount($since),
            // And the slowest of them, one message to a row: which is where
            // "95% within 15 min" turns into spam collected on schedule.
            'slowest' => $this->messages->slowestArrivals($since, self::SLOWEST_ROWS),
            'workers' => $this->workers(),
            // The model host's card is fetched by the page itself, the way
            // Admin → AI did it — a host that is switched off must not hold
            // this page for as long as its timeout. All that is decided here
            // is whether there is anything to poll.
            'aiLive'  => true === $settings->isEnabled && true === $settings->isConfigured(),
            'hold'    => [
                'enabled' => $settings->holdUntilClassified,
                'ceiling' => $settings->holdSeconds(),
                'stats'   => $this->messages->holdDelayStats($since),
                'rows'    => $this->messages->holdDetails($since, self::DETAIL_ROWS),
                'limit'   => self::DETAIL_ROWS,
            ],
        ]);
    }

    /**
     * Each worker, and how long work has been waiting for it right now.
     *
     * BY WORKER, where System lists the same numbers by queue. The question
     * here is "which process is behind", and a worker that consumes two queues
     * in priority order is one answer, not two: `enrich_backlog` waiting an
     * hour is the design, not a fault, as long as `enrich` beside it is empty.
     * So the queues are listed under the worker that owns them, in the order
     * it looks at them.
     *
     * The scheduler is left out. It consumes a schedule, not a queue; nothing
     * waits for it.
     *
     * A reading of this moment, unlike the rest of the page: the transport
     * keeps no history of how long a job waited once it has been handled.
     *
     * @return list<array{worker: string, queues: list<array{queue: string, pending: int, running: int,
     *     waitingSeconds: int|null, runningSeconds: int|null}>}>
     */
    private function workers(): array
    {
        $byQueue = [];

        foreach ($this->queues->queueStats() as $stat) {
            $byQueue[$stat['queue']] = $stat;
        }

        $workers = [];

        foreach ($this->processes->consumers() as $worker => $transports) {
            if ('scheduler' === $worker) {
                continue;
            }

            $queues = [];

            foreach ($transports as $transport) {
                $stat = $byQueue[$transport] ?? null;

                $queues[] = [
                    'queue'          => $transport,
                    'pending'        => $stat['pending'] ?? 0,
                    'running'        => $stat['running'] ?? 0,
                    // How long the oldest waiting job has existed — not how
                    // long since it last became eligible, which a retry resets.
                    'waitingSeconds' => $stat['waitingSinceSeconds'] ?? null,
                    'runningSeconds' => $stat['runningForSeconds'] ?? null,
                ];
            }

            $workers[] = ['worker' => $worker, 'queues' => $queues];
        }

        return $workers;
    }
}
