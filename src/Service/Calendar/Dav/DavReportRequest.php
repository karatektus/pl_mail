<?php

declare(strict_types=1);

namespace App\Service\Calendar\Dav;

use DateTimeImmutable;

/**
 * A REPORT body, read.
 *
 * Three reports arrive at the same URL and are told apart only by the name of
 * the root element, so this is what the controller dispatches on. They ask
 * different questions of the same collection:
 *
 *   sync-collection    what changed since this token
 *   calendar-multiget  these exact resources, by href
 *   calendar-query     the resources matching this filter
 *
 * A null syncToken means the client holds nothing yet. RFC 6578 spells that as
 * an empty <sync-token/> element and clients also send it absent entirely, so
 * both become null here and the reader turns null into "from the beginning" —
 * which is a full listing, and correct.
 */
final readonly class DavReportRequest
{
    public const string SYNC_COLLECTION = 'sync-collection';
    public const string MULTIGET        = 'calendar-multiget';
    public const string QUERY           = 'calendar-query';

    /**
     * @param list<string> $hrefs
     */
    public function __construct(
        public string $type,
        public ?string $syncToken = null,
        public ?int $limit = null,
        public array $hrefs = [],
        public bool $wantsCalendarData = false,
        public ?DateTimeImmutable $rangeStart = null,
        public ?DateTimeImmutable $rangeEnd = null,
    ) {
    }
}
