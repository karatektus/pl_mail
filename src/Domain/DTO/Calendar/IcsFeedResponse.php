<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * What one conditional GET of a published .ics answered.
 *
 * Two outcomes and one shape, because the interesting one costs nothing: a feed
 * nobody touched answers 304 with no body at all, and that is the answer to
 * most polls of a holiday calendar. Expressed as a flag beside an empty body
 * rather than as a nullable body, so a caller that forgets to check it reads an
 * empty calendar rather than a null it has to guess the meaning of.
 *
 * $etag and $lastModified are carried back out verbatim and re-presented on the
 * next poll. They are the whole reason the 304 exists, and they are opaque here
 * in exactly the sense CalendarSyncDriverInterface means: nothing parses,
 * orders or compares them for anything but equality.
 *
 * A 304 carries neither in practice — RFC 9110 permits an ETag on one and most
 * servers omit it — so the driver keeps the token it already had rather than
 * downgrading to an unconditional read on the next run. That is why both are
 * nullable on a response that is unchanged, and why $isUnchanged is the flag
 * the driver branches on rather than "did I get validators back".
 */
final readonly class IcsFeedResponse
{
    /**
     * @param bool        $isUnchanged  the server answered 304; $body is empty
     *                                  and says nothing about the calendar
     * @param string      $body         the iCalendar document, empty when
     *                                  unchanged
     * @param string|null $etag         the ETag header verbatim, quotes and any
     *                                  W/ prefix included, or null when the
     *                                  server sent none
     * @param string|null $lastModified the Last-Modified header verbatim, or
     *                                  null when the server sent none
     */
    public function __construct(
        public bool    $isUnchanged,
        public string  $body = '',
        public ?string $etag = null,
        public ?string $lastModified = null,
    ) {
    }

    /** Nothing has changed since the validators the caller presented. */
    public static function unchanged(): self
    {
        return new self(true);
    }
}
