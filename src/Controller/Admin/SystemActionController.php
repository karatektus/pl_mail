<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Monitoring\ProcessHeartbeatService;
use App\Service\Monitoring\QueueMonitor;
use App\Service\Monitoring\WorkerRestartSignal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Maintenance actions for the admin System panel.
 *
 * Separate from AdminDashboardController, which is read-side plus the three
 * mutations that belong to their own cards. This one holds the verbs that act
 * on the running system.
 *
 * No responses of substance: each action redirects back to the dashboard and
 * the panel's own 10s auto-refresh shows the result — queue depth rises,
 * failed rows disappear, heartbeats reappear. Admin has no toast plumbing
 * (#toast-region is filled by Turbo Streams, and these are plain redirects),
 * so the panel IS the feedback. The exception is the restart, which has no
 * visible effect; the live frame renders a banner from
 * WorkerRestartSignal::requestedAt() instead.
 */
#[Route('/admin/system', name: 'app_admin_system_')]
#[IsGranted('ROLE_ADMIN')]
final class SystemActionController extends AbstractController
{
    /**
     * Slug => console command line. A whitelist, not a passthrough: the value
     * reaches a command runner, so request input must never build it.
     *
     * These four are the schedule from MaintenanceSchedule — already proven
     * idempotent by running unattended on a cron, which is what makes them
     * safe to expose as buttons.
     */
    private const array RUNNABLE = [
        'mail-sync'       => 'app:mail:sync',
        'push-renew'      => 'app:push:renew --repair',
        'monitoring-prune' => 'app:monitoring:prune',
        'prune-blobs'     => 'app:prune:blobs',
    ];

    public function __construct(
        private readonly WorkerRestartSignal     $restartSignal,
        private readonly QueueMonitor            $queueMonitor,
        private readonly ProcessHeartbeatService $heartbeats,
        private readonly MessageBusInterface     $bus,
    ) {}

    /**
     * Ask every long-running process to exit; compose's restart policy brings
     * them back. The reason this exists: a worker caches Doctrine metadata for
     * its whole lifetime, so after a migration it can keep querying columns
     * that no longer exist until something restarts it.
     */
    #[Route('/restart-workers', name: 'restart_workers', methods: ['POST'])]
    public function restartWorkers(Request $request): Response
    {
        $this->validateCsrf($request, 'admin_restart_workers');

        $this->restartSignal->request();

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/run/{task}', name: 'run', methods: ['POST'])]
    public function run(Request $request, string $task): Response
    {
        $this->validateCsrf($request, 'admin_run_' . $task);

        $command = self::RUNNABLE[$task] ?? null;

        if (null === $command) {
            throw $this->createNotFoundException('Unknown maintenance task.');
        }

        // Routed to async in messenger.yaml, so messenger-worker runs this.
        // Unrouted it would execute synchronously inside this request.
        $this->bus->dispatch(new RunCommandMessage($command));

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/failed/retry-all', name: 'failed_retry_all', methods: ['POST'])]
    public function retryAllFailed(Request $request): Response
    {
        $this->validateCsrf($request, 'admin_failed_retry_all');

        $this->queueMonitor->retryAll();

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/failed/purge', name: 'failed_purge', methods: ['POST'])]
    public function purgeFailed(Request $request): Response
    {
        $this->validateCsrf($request, 'admin_failed_purge');

        $this->queueMonitor->purgeAll();

        return $this->redirectToRoute('app_admin_dashboard');
    }

    /**
     * Clears heartbeat rows left behind by processes that died without a clean
     * shutdown — the ones that would otherwise sit red in the Processes card
     * forever.
     */
    #[Route('/heartbeats/prune', name: 'heartbeats_prune', methods: ['POST'])]
    public function pruneHeartbeats(Request $request): Response
    {
        $this->validateCsrf($request, 'admin_heartbeats_prune');

        $this->heartbeats->pruneStale();

        return $this->redirectToRoute('app_admin_dashboard');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function validateCsrf(Request $request, string $tokenId): void
    {
        $token = (string) $request->request->get('_token', '');

        if (false === $this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
