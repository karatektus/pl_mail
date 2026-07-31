<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Monitoring\AdminMonitoringService;
use App\Service\Monitoring\QueueMonitor;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Is this instance actually working?
 *
 * Every compose service but Mercure had `healthcheck: disable: true`, so a
 * stack could report itself perfectly healthy while the database was
 * unreachable or every worker was dead — the two failures that stop mail
 * arriving. This is what those healthchecks probe, and what a reverse proxy or
 * uptime monitor can watch from outside.
 *
 * **Unauthenticated on purpose, and therefore deliberately vague.** A probe
 * that needed a session would be useless to Docker and to every monitoring
 * tool, so this is reachable by anyone who can reach the port. It answers only
 * what such a caller may already infer by trying the app: whether it responds
 * at all, and whether its dependencies are up. It exposes no counts, no
 * addresses, no account names and no version — `/admin` is where the numbers
 * live, behind ROLE_ADMIN.
 *
 * 200 when serving is possible, 503 when it is not, because that is the
 * distinction an orchestrator acts on. Degraded-but-serving stays 200: a
 * backed-up queue means mail is late, not that the instance should be taken
 * out of rotation and restarted.
 */
final class HealthController extends AbstractController
{
    /**
     * Past this, the queue is not merely busy — something is not consuming it.
     * Generous, because a first sync of a large mailbox legitimately queues
     * thousands of jobs and must not be reported as an outage.
     */
    private const int QUEUE_BACKLOG_THRESHOLD = 5000;

    public function __construct(
        private readonly Connection             $connection,
        private readonly QueueMonitor           $queueMonitor,
        private readonly AdminMonitoringService $monitoring,
    ) {}

    #[Route('/healthz', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $database = $this->databaseIsUp();

        // Only the database decides the status code. Without it nothing works;
        // with it the app still serves mail that is already synced, even if
        // every worker is down.
        $checks = [
            'database' => $database,
            'queue'    => $database ? $this->queueIsMoving() : null,
            'workers'  => $database ? $this->workersAreAlive() : null,
        ];

        return new JsonResponse(
            [
                'status' => $database ? 'ok' : 'error',
                'checks' => $checks,
            ],
            $database ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function databaseIsUp(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable) {
            // Swallowed deliberately: the caller gets false, and the reason is
            // in the logs. A probe that returned the connection error would
            // hand an unauthenticated caller the database host and user.
            return false;
        }
    }

    /**
     * Null when the answer cannot be determined, which is not the same as
     * unhealthy — a monitor should be able to tell "the queue is backed up"
     * from "I could not look".
     */
    private function queueIsMoving(): ?bool
    {
        try {
            $pending = 0;

            foreach ($this->queueMonitor->queueStats() as $queue) {
                $pending += $queue['pending'];
            }

            return $pending < self::QUEUE_BACKLOG_THRESHOLD;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True when every process that has ever reported in is still beating.
     *
     * Heartbeat-based rather than process-based, so it covers the workers that
     * run in their own containers — the scheduler and the IMAP supervisor —
     * which the web container cannot see any other way.
     *
     * An install that has never run a worker has no heartbeats at all, and
     * gets null rather than false: nothing has failed, there is simply nothing
     * to report yet.
     */
    private function workersAreAlive(): ?bool
    {
        try {
            $beats = $this->monitoring->heartbeats();

            if ([] === $beats) {
                return null;
            }

            foreach ($beats as $beat) {
                if (false === $beat['healthy']) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return null;
        }
    }
}
