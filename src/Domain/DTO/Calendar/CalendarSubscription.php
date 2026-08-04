<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Entity\Calendar\Calendar;

/**
 * One calendar a connection offers, beside the local row mirroring it — or the
 * absence of one.
 *
 * What the subscribe screen draws a row from. Discovery answers with
 * RemoteCalendar and nothing else, because a driver has no business knowing
 * what plMail already stores; pairing it with the local Calendar is a question
 * about this database, and it has to be answered somewhere between the driver
 * and the template. Here, so the template gets one list and renders it once
 * rather than looking each remote up in a second map handed alongside.
 *
 * Carries the local entity rather than a boolean. The row shows what
 * subscribing already produced — the colour it was given, whether it is
 * read-only, when it last synced — and a boolean would mean the template
 * asking a second structure for all of that by id.
 */
final readonly class CalendarSubscription
{
    public function __construct(
        public RemoteCalendar $remote,
        public ?Calendar      $local = null,
    ) {
    }

    /**
     * Stays a method rather than becoming a property: it reads a nullable
     * relation and answers a question about it, which is an interpretation
     * rather than the plain read $remote is. Integration::isHealthy() is the
     * same shape for the same reason.
     */
    public function isSubscribed(): bool
    {
        return null !== $this->local;
    }
}
