<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Any failure talking to a remote calendar.
 *
 * Shaped by what the caller should do, not by what went wrong — the same rule
 * the Gmail and Graph hierarchies follow, for the same reason: a sync worker
 * has exactly three useful responses to a failure (stop, wait, start over) and
 * a bare status code tells it which one only if it also knows the provider.
 * Translating at the driver boundary is what keeps that knowledge inside the
 * driver.
 *
 *   CalendarSyncException            — unclassified; the transport's own retry
 *                                      strategy decides
 *   ├── CalendarSyncPermanentException — stop, this will never work
 *   ├── CalendarSyncThrottledException — back off and retry
 *   └── CalendarResyncRequiredException — the token is dead, read from scratch
 *
 * The message lands in Calendar::$lastSyncError and is shown in the calendar
 * settings list, so it is phrased for a person and must never carry a
 * credential, a bearer token or a full request URL.
 *
 * Carries no Messenger marker itself, deliberately: an unclassified failure
 * falls back to the ingest transport's strategy, which is the behaviour a
 * driver that has not yet learned to classify something should get.
 */
class CalendarSyncException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** The HTTP status behind this, or 0 where the failure was not an HTTP one. */
    public function getStatus(): int
    {
        return $this->status;
    }
}
