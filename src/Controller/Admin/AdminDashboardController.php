<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\LogEntryRepository;
use App\Service\Monitoring\AdminMonitoringService;
use App\Service\Monitoring\QueueMonitor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'app_admin_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminDashboardController extends AbstractController
{
    private const int LOGS_PER_PAGE = 100;

    /** Monolog numeric levels offered as minimum-level filters. */
    private const array LOG_LEVELS = [
        200 => 'info',
        250 => 'notice',
        300 => 'warning',
        400 => 'error',
        500 => 'critical',
    ];

    public function __construct(
        private readonly AdminMonitoringService $monitoring,
        private readonly QueueMonitor           $queueMonitor,
        private readonly LogEntryRepository     $logEntryRepository,
    ) {}

    #[Route('', name: 'dashboard')]
    public function index(): Response
    {
        return $this->render('admin/index.html.twig');
    }

    /**
     * Auto-refreshed live panels. Loaded (and re-loaded) as a Turbo Frame.
     */
    #[Route('/live', name: 'live')]
    public function live(): Response
    {
        return $this->render('admin/_live_frame.html.twig', [
            'heartbeats'      => $this->monitoring->heartbeats(),
            'webhooks'        => $this->monitoring->gmailWebhooks(),
            'tokenHealth'     => $this->monitoring->tokenHealth(),
            'queueStats'      => $this->queueMonitor->queueStats(),
            'failedMessages'  => $this->queueMonitor->failedMessages(),
            'accountOverview' => $this->monitoring->accountOverview(),
            'tableSizes'      => $this->monitoring->tableSizes(),
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

        return $this->render('admin/_logs_frame.html.twig', [
            'entries'  => $entries,
            'total'    => $total,
            'page'     => $page,
            'pages'    => max(1, (int) ceil($total / self::LOGS_PER_PAGE)),
            'minLevel' => $minLevel,
            'channel'  => $channel,
            'levels'   => self::LOG_LEVELS,
            'channels' => $this->logEntryRepository->distinctChannels(),
        ]);
    }

    #[Route('/failed/{id}/retry', name: 'failed_retry', methods: ['POST'])]
    public function retryFailed(Request $request, string $id): Response
    {
        $this->validateCsrf($request, $id);

        $this->queueMonitor->retry($id);

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/failed/{id}/delete', name: 'failed_delete', methods: ['POST'])]
    public function deleteFailed(Request $request, string $id): Response
    {
        $this->validateCsrf($request, $id);

        $this->queueMonitor->remove($id);

        return $this->redirectToRoute('app_admin_dashboard');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function validateCsrf(Request $request, string $id): void
    {
        $token = (string) $request->request->get('_token', '');

        if (false === $this->isCsrfTokenValid('admin_failed_' . $id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
