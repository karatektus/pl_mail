<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Repository\Monitoring\ProcessHeartbeatRepository;
use App\Service\Monitoring\ProcessHeartbeatService;
use DateTimeImmutable;

/**
 * When `app:push:renew` last finished a run, and whether that was long enough
 * ago to be the explanation for an expired registration.
 *
 * ── Why this exists as its own service ───────────────────────────────────────
 * The failure it makes visible is the one with no error attached to it.
 * MaintenanceSchedule runs renewal at 04:00 daily; if the scheduler worker is
 * down, or its stateful cache was cleared, or the container never came back
 * after a deploy, then renewal does not fail — it does not happen. Nothing is
 * logged, nothing is raised, and the first symptom is a watch quietly expiring
 * a week later with no trace of why.
 *
 * PushRenewCommand now records each completed run as a heartbeat, and this
 * reads it back for the health page. Deliberately a thin read over
 * ProcessHeartbeat rather than a new table: "X was last heard from at T" is
 * exactly what that table stores, and it already survives the reset that clears
 * monitoring data in the same way the rest of monitoring does.
 *
 * Every method here answers "unknown" rather than throwing. This backs a health
 * card, and a health page that 500s because its own monitoring read failed is
 * worse than one that says it does not know.
 */
final readonly class PushRenewalRecord
{
    public function __construct(
        private ProcessHeartbeatRepository $heartbeats,
    ) {
    }

    /**
     * The last completed run, or null if none was ever recorded.
     *
     * Null is genuinely ambiguous and the surface says so rather than guessing:
     * it means either that renewal has never run, or that it last ran before
     * this recording existed. Both point the reader at the same thing to check,
     * which is why one wording covers them.
     */
    public function lastRunAt(): ?DateTimeImmutable
    {
        try {
            $beat = $this->heartbeats->findOneBy([
                'type' => ProcessHeartbeatService::TYPE_PUSH_RENEW,
                'key'  => 'main',
            ]);
        } catch (\Throwable) {
            return null;
        }

        return $beat?->lastBeatAt;
    }

    /**
     * Whether renewal is overdue — no run inside the window a daily schedule
     * should always produce one in. See
     * ProcessHeartbeatService::MILESTONE_THRESHOLDS for the 25 hours.
     *
     * A never-recorded run counts as overdue only once there is something to
     * renew; this answers the narrow question and the caller decides whether it
     * is worth saying. An install that has never had a push account has never
     * needed renewal to run.
     */
    public function isOverdue(): bool
    {
        $last = $this->lastRunAt();

        if (null === $last) {
            return true;
        }

        $threshold = ProcessHeartbeatService::MILESTONE_THRESHOLDS[ProcessHeartbeatService::TYPE_PUSH_RENEW];

        return $last->getTimestamp() < time() - $threshold;
    }
}
