<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Repository\Maintenance\BackfillRun\BackfillRunRepository;
use App\Service\Maintenance\Backfill\BackfillCatalogue;
use App\Service\Maintenance\Backfill\BackfillRunner;
use App\Service\Maintenance\Backfill\BackfillTasks;
use App\Service\Maintenance\Backfill\BackfillWindow;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Backfills: run the repairs `app:backfill` runs, without a terminal.
 *
 * A list of every task, newest first, and the chosen one's explanation beside
 * it, so an administrator reads what a task does before pressing Run. A task
 * that can count beforehand offers to (CountableBackfillTaskInterface); one
 * that re-reads mail takes a window. Run queues it for the maintenance worker
 * (BackfillRunner), and the Recent runs card under it shows what the run
 * printed while it goes.
 *
 * Two Turbo Frames, as Admin → Push has: the list and detail, which only
 * changes when somebody clicks, and the runs, which reload themselves while a
 * run is going (admin--backfill-runs). Reloading the first would throw away a
 * window somebody had just picked.
 *
 * The task comes from the path and is looked up in the registry; a name that
 * is not a registered task is a 404, never a guess. Request input never
 * reaches a command line here — the tasks are objects, called directly.
 */
#[Route('/admin/backfills', name: 'app_admin_backfills_')]
#[IsGranted('ROLE_ADMIN')]
final class BackfillAdminController extends AbstractController
{
    use ChecksCsrf;

    /** How many runs the Recent runs card shows. */
    private const int RECENT_RUNS = 15;

    public function __construct(
        private readonly BackfillTasks         $tasks,
        private readonly BackfillCatalogue     $catalogue,
        private readonly BackfillRunner        $runner,
        private readonly BackfillRunRepository $runs,
    ) {}

    #[Route('', name: 'panel', methods: ['GET'])]
    public function panel(Request $request): Response
    {
        return $this->panelFor(
            (string) $request->query->get('task', ''),
            (string) $request->query->get('window', BackfillWindow::DEFAULT),
            null,
        );
    }

    /**
     * Count what the chosen task would look at, and draw the panel again with
     * the number. A POST, because the count can be a scan of the message
     * table, and a GET would let any page start one with an <img>.
     */
    #[Route('/{task}/count', name: 'count', requirements: ['task' => '[a-z0-9-]+'], methods: ['POST'])]
    public function count(Request $request, string $task): Response
    {
        $this->assertCsrf($request, 'admin-backfills');

        $found  = $this->tasks->find($task) ?? throw $this->createNotFoundException();
        $window = $this->window($request);

        return $this->panelFor($task, $window, $this->runner->count($found, $window));
    }

    #[Route('/{task}/run', name: 'run', requirements: ['task' => '[a-z0-9-]+'], methods: ['POST'])]
    public function run(Request $request, string $task): Response
    {
        $this->assertCsrf($request, 'admin-backfills');

        $found = $this->tasks->find($task) ?? throw $this->createNotFoundException();

        /** @var User $user */
        $user = $this->getUser();

        $this->runner->queue($found, $this->window($request), $user);

        return $this->redirectToRoute('app_admin_backfills_panel', [
            'task'   => $task,
            'window' => $this->window($request),
        ], Response::HTTP_SEE_OTHER);
    }

    /** The Recent runs card on its own, for its reloads. */
    #[Route('/runs', name: 'runs', methods: ['GET'])]
    public function recentRuns(): Response
    {
        return $this->render('admin/backfills/_runs.html.twig', $this->runsContext());
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function panelFor(string $task, string $window, ?int $count): Response
    {
        $now     = new DateTimeImmutable();
        $entries = $this->catalogue->entries($now);

        // The newest task when none is asked for, or an unknown one: it is the
        // one an administrator arriving from the release notes came for.
        $selected = null;

        foreach ($entries as $entry) {
            if ($entry->name === $task) {
                $selected = $entry;
            }
        }

        $selected ??= $entries[0] ?? null;

        return $this->render('admin/backfills/_frame.html.twig', [
            'entries'  => $entries,
            'selected' => $selected,
            'window'   => true === in_array($window, BackfillWindow::PRESETS, true) ? $window : BackfillWindow::DEFAULT,
            'windows'  => BackfillWindow::PRESETS,
            'count'    => null !== $selected && $selected->name === $task ? $count : null,
            'active'   => null !== $selected ? $this->runs->activeForTask($selected->name) : null,
            ...$this->runsContext(),
        ]);
    }

    /** @return array<string, mixed> */
    private function runsContext(): array
    {
        $now = new DateTimeImmutable();

        return [
            'runs' => $this->runs->recent(self::RECENT_RUNS),
            'now'  => $now,
        ];
    }

    private function window(Request $request): string
    {
        $window = (string) $request->request->get('window', BackfillWindow::DEFAULT);

        return true === in_array($window, BackfillWindow::PRESETS, true) ? $window : BackfillWindow::DEFAULT;
    }
}
