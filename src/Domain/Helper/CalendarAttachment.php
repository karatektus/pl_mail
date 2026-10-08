<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use App\Entity\Mail\MessagePart;

/**
 * Whether a part of a message is calendar data.
 *
 * One answer, because three places ask and they have to agree: the runner that
 * hands parts to IcsEventExtractor, the proposer that stands aside when a real
 * event is coming, and the query that picks stored mail to re-read. Each used
 * to carry its own copy of "text/calendar or application/ics", and that was
 * the whole test.
 *
 * It is not the whole of what arrives. An invitation a calendar program sends
 * is a text/calendar part; an .ics that a person, or a recruiting system,
 * ATTACHES is very often declared as application/octet-stream — "a file" — and
 * says what it is only in its name. That one was stored as an attachment and
 * read by nothing: the message offered a download, and the date in it reached
 * no calendar.
 *
 * So the name counts too. The name alone is a claim, not a proof, and it does
 * not need to be one: the bytes still go through the calendar parser, which
 * makes nothing of a file that is not a calendar.
 */
final class CalendarAttachment
{
    /**
     * Content types that mean "there is an invite in here".
     *
     * @var list<string>
     */
    public const array CONTENT_TYPES = ['text/calendar', 'application/ics'];

    /** What a calendar file is called, whatever it was declared as. */
    public const string EXTENSION = '.ics';

    public static function is(MessagePart $part): bool
    {
        $type = mb_strtolower(trim((string) $part->contentType));

        if (true === in_array($type, self::CONTENT_TYPES, true)) {
            return true;
        }

        return true === str_ends_with(mb_strtolower(trim((string) $part->filename)), self::EXTENSION);
    }
}
