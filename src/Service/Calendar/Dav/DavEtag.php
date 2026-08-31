<?php

declare(strict_types=1);

namespace App\Service\Calendar\Dav;

use App\Entity\Calendar\CalendarEvent;

/**
 * The ETag on one calendar resource.
 *
 * A CalDAV client uses this for two things, and the second is why it has to be
 * exact rather than approximately right: to skip fetching a resource it already
 * holds, and as the If-Match on a PUT. A stale ETag on the first costs a wasted
 * GET. On the second it costs data — the client believes it is editing the
 * version it read, the precondition passes anyway, and somebody else's change is
 * overwritten with no error to notice.
 *
 * So the ETag is taken from the change log's own sequence wherever there is one.
 * That is the same number sync-collection counts in, which means the two can
 * never disagree about whether something changed: a write that moved the token
 * moved the ETag, because they are the same fact.
 *
 * ── The fallback, and why it is needed ────────────────────────────────────
 *
 * The log starts empty, so every event that existed before it was introduced has
 * no row and no sequence. Those fall back to updatedAt and the iCalendar
 * SEQUENCE, which is the best available and is good to the second.
 *
 * That is a real, bounded weakness: two edits to the same pre-log event inside
 * one second, neither bumping SEQUENCE, produce the same ETag. It shrinks to
 * nothing on its own — the first write to any event after this shipped gives it
 * a log row, and it never uses the fallback again. Worth knowing rather than
 * worth engineering around, since the alternative is hashing the serialised
 * iCalendar of every event in a collection listing.
 */
final readonly class DavEtag
{
    /**
     * @param int|null $logSequence highest change-log sequence for this event,
     *                              or null when it has never been logged
     */
    public function for(CalendarEvent $event, ?int $logSequence): string
    {
        if (null !== $logSequence) {
            return sprintf('"%d-%d"', $event->id, $logSequence);
        }

        return sprintf(
            '"%d-p%s"',
            $event->id,
            substr(sha1($event->updatedAt->format('U') . ':' . $event->sequence), 0, 16),
        );
    }

    /**
     * Whether an If-Match header allows a write.
     *
     * `*` means "as long as it exists", which is what a client sends when it
     * only wants to avoid creating something new. A list is matched member by
     * member: RFC 9110 allows several, and a client that read a resource twice
     * may legitimately hold more than one.
     */
    public function matches(?string $ifMatch, string $current): bool
    {
        if (null === $ifMatch || '' === trim($ifMatch)) {
            return true;
        }

        $ifMatch = trim($ifMatch);

        if ('*' === $ifMatch) {
            return true;
        }

        foreach (explode(',', $ifMatch) as $candidate) {
            // Weak comparison is the right one here: a weak validator still
            // names the same version, and clients have been seen to add the
            // W/ prefix to a tag they were given without one.
            $candidate = ltrim(trim($candidate), 'W/');

            if (trim($candidate) === $current) {
                return true;
            }
        }

        return false;
    }
}
