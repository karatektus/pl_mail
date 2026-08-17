<?php

declare(strict_types=1);

namespace App\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Mail\Message;
use App\Service\Insight\InsightDraft;
use App\Service\Insight\InsightExtractorInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Ticket-shop confirmations → one dated card per event.
 *
 * The gate is a list of shops plus a handful of subject phrases, and the
 * date is NOT optional: a ticket card exists to answer "when is this?", and
 * a mail whose date cannot be read deterministically is dropped rather than
 * shown undated — the same refusal DeterministicDateDetector makes next
 * door, for the same reason. That refusal is also what keeps the phrase
 * gate honest: "Deine Tickets warten!" marketing passes supports() and then
 * states no date, so it makes no card.
 *
 * The dedupe key hashes (event name, day) rather than anything from the
 * mail's own identity, so the confirmation and the day-before reminder —
 * different senders, different subjects, same concert — land on one card
 * and the newest mail wins (the harvester upserts).
 */
final readonly class TicketExtractor implements InsightExtractorInterface
{
    /**
     * When a ticket mail states a day but no time. Doors are an evening
     * thing, and 19:00 sorts the card into the right part of the day without
     * pretending to a precision the mail did not offer — dateText keeps what
     * was actually written.
     */
    private const int DEFAULT_HOUR = 19;

    /**
     * How far past the date a stated time may sit and still belong to it.
     * "05.06.2027, Einlass 18:30" is a handful of characters; a footer's
     * office hours are not.
     */
    private const int TIME_WINDOW_CHARS = 40;

    /**
     * Shops whose confirmation mails this parser has been shaped on. Matched
     * against the sender's domain, subdomains included — Eventbrite really
     * does send from @order.eventbrite.de.
     *
     * @var list<string>
     */
    private const array VENDOR_DOMAINS = [
        'eventbrite.com', 'eventbrite.de',
        'ticketmaster.de', 'ticketmaster.com',
        'eventim.de', 'adticket.de', 'reservix.de',
        'cinestar.de', 'cinemaxx.de', 'uci-kinowelt.de',
        'dice.fm',
    ];

    /**
     * The other gate: a small venue mailing from its own domain still writes
     * one of these in the subject. Lowercase, compared against a lowercased
     * subject. Looser than the domain list on purpose — extract() demanding
     * a date is what pays for the looseness.
     *
     * @var list<string>
     */
    private const array SUBJECT_PHRASES = [
        'your tickets', 'deine tickets', 'ihre tickets',
        'ticket confirmation', 'ticketbestätigung',
    ];

    /**
     * Subject furniture in front of the event's actual name. Lowercase; the
     * comparison folds case so "DEINE TICKETS FÜR" still strips.
     *
     * @var list<string>
     */
    private const array NAME_PREFIXES = [
        'your tickets for ',
        'deine tickets für ',
        'ihre tickets für ',
        'tickets: ',
        'ticketbestätigung: ',
        'order confirmed: ',
    ];

    /**
     * Month names, both languages, written out rather than derived from
     * IntlDateFormatter — same reasoning as DeterministicDateDetector::MONTHS:
     * the parse must give the same answer on every machine, and ICU data is a
     * system package that differs between container and laptop.
     *
     * @var array<string, int>
     */
    private const array MONTHS = [
        'januar' => 1, 'january' => 1, 'jan' => 1,
        'februar' => 2, 'february' => 2, 'feb' => 2,
        'märz' => 3, 'maerz' => 3, 'march' => 3, 'mar' => 3, 'mrz' => 3,
        'april' => 4, 'apr' => 4,
        'mai' => 5, 'may' => 5,
        'juni' => 6, 'june' => 6, 'jun' => 6,
        'juli' => 7, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9,
        'oktober' => 10, 'october' => 10, 'okt' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'dezember' => 12, 'december' => 12, 'dez' => 12, 'dec' => 12,
    ];

    public static function key(): string
    {
        return 'ticket';
    }

    public function icon(): string
    {
        return 'fa-solid fa-ticket';
    }

    public function priority(): int
    {
        return 80;
    }

    public function supports(Message $message): bool
    {
        $address = mb_strtolower(trim((string) $message->fromAddress));
        $at = strrpos($address, '@');

        if (false !== $at) {
            $domain = substr($address, $at + 1);

            foreach (self::VENDOR_DOMAINS as $vendor) {
                if ($domain === $vendor || true === str_ends_with($domain, '.' . $vendor)) {
                    return true;
                }
            }
        }

        $subject = mb_strtolower((string) $message->subject);

        foreach (self::SUBJECT_PHRASES as $phrase) {
            if (true === str_contains($subject, $phrase)) {
                return true;
            }
        }

        return false;
    }

    public function extract(Message $message): array
    {
        $body = (string) $message->bodyText;

        $eventName = $this->eventName($message);

        if (null === $eventName) {
            return [];
        }

        $date = $this->dateIn($body);

        if (null === $date) {
            return [];
        }

        return [new InsightDraft(
            kind: InsightKind::Ticket,
            title: $eventName,
            // The thing's identity is (event, day): the reminder must land on
            // the confirmation's card. Hashed because event names are prose
            // and the harvester's key column is finite; folded to lower case
            // because "ROCK IM PARK" and "Rock im Park" are one concert.
            dedupeKey: substr(
                sha1(mb_strtolower($eventName) . '|' . $date['happensAt']->format('Y-m-d')),
                0,
                24,
            ),
            payload: [
                'eventName' => $eventName,
                'venue' => $this->venueIn($body),
                'dateText' => $date['text'],
            ],
            happensAt: $date['happensAt'],
        )];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The subject minus its shop furniture, or — when there is no subject at
     * all — the first short line of the body: confirmations lead with the
     * event's name, and a line under eighty characters is a name where a
     * longer one is prose.
     */
    private function eventName(Message $message): ?string
    {
        $subject = trim((string) $message->subject);

        if ('' !== $subject) {
            $lower = mb_strtolower($subject);

            foreach (self::NAME_PREFIXES as $prefix) {
                if (true === str_starts_with($lower, $prefix)) {
                    $stripped = trim(mb_substr($subject, mb_strlen($prefix)));

                    if ('' !== $stripped) {
                        return $stripped;
                    }
                }
            }

            return $subject;
        }

        foreach (preg_split('~\r?\n~', (string) $message->bodyText) ?: [] as $line) {
            $line = trim($line);

            if ('' !== $line && 80 > mb_strlen($line)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * The first explicit date the body states, with a time when one sits
     * right next to it and the evening default when none does.
     *
     * Only forms a person can write without ambiguity, and between the forms
     * reading order decides: whichever appears FIRST in the body is the
     * event's date, because the shop states the event before the footer
     * states anything.
     *
     * @return array{happensAt: DateTimeImmutable, text: string}|null
     */
    private function dateIn(string $body): ?array
    {
        $months = implode('|', array_keys(self::MONTHS));

        // Pattern, then which capture is day / month / year, then whether the
        // month capture is a name to look up rather than a number.
        $shapes = [
            // 2027-06-05 — the one form no locale reads two ways.
            ['~\b(\d{4})-(\d{1,2})-(\d{1,2})\b~u', 3, 2, 1, false],
            // 05.06.2027 — dots mean day-first wherever they appear, and the
            // four-digit year is what refuses the 04.08.26 fragment.
            ['~\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b~u', 1, 2, 3, false],
            // 24. Dezember 2026 — the month name removes the day/month doubt.
            [sprintf('~\b(\d{1,2})\.\s*(%s)\s+(\d{4})\b~iu', $months), 1, 2, 3, true],
            // December 24, 2026.
            [sprintf('~\b(%s)\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})\b~iu', $months), 2, 1, 3, true],
        ];

        $best = null;

        foreach ($shapes as [$pattern, $d, $m, $y, $named]) {
            if (1 !== preg_match($pattern, $body, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $month = true === $named ? self::MONTHS[mb_strtolower($match[$m][0])] : (int) $match[$m][0];
            $day = (int) $match[$d][0];
            $year = (int) $match[$y][0];

            if (false === checkdate($month, $day, $year)) {
                continue;
            }

            if (null === $best || $match[0][1] < $best['offset']) {
                $best = [
                    'day' => $day, 'month' => $month, 'year' => $year,
                    'offset' => $match[0][1], 'text' => $match[0][0],
                ];
            }
        }

        if (null === $best) {
            return null;
        }

        // The window is bytes after the date match, matching the byte offsets
        // preg gave us; a time further away is an unrelated fact.
        $window = substr($body, $best['offset'] + strlen($best['text']), self::TIME_WINDOW_CHARS);

        $hour = self::DEFAULT_HOUR;
        $minute = 0;
        $text = $best['text'];

        if (1 === preg_match('~\b(\d{1,2}):(\d{2})\b~', $window, $time)
            && 24 > (int) $time[1] && 60 > (int) $time[2]
        ) {
            $hour = (int) $time[1];
            $minute = (int) $time[2];
            $text .= ', ' . $time[0];
        }

        return [
            // UTC for want of anything stated: ticket mails print a wall
            // clock with no zone, and the card shows it back as written.
            'happensAt' => new DateTimeImmutable(
                sprintf('%04d-%02d-%02d %02d:%02d:00', $best['year'], $best['month'], $best['day'], $hour, $minute),
                new DateTimeZone('UTC'),
            ),
            'text' => $text,
        ];
    }

    /**
     * A venue only where a line SAYS it is one: the labelled forms both
     * shop languages use, or a bare "at <place>" line. Null when unsure —
     * a wrong venue on a card is worse than a missing one.
     */
    private function venueIn(string $body): ?string
    {
        $lines = preg_split('~\r?\n~', $body) ?: [];

        foreach ($lines as $line) {
            if (1 === preg_match('~^\s*(?:Ort|Venue)\s*:\s*(.{2,80})$~iu', trim($line), $match)) {
                return trim($match[1]);
            }
        }

        foreach ($lines as $line) {
            // Must contain a letter — "at 20:00" is a time, not a place —
            // and stay short enough to be a name rather than a sentence.
            if (1 === preg_match('~^at\s+(.{2,80})$~i', trim($line), $match)
                && 1 === preg_match('~\p{L}~u', $match[1])
                && 1 !== preg_match('~^\d{1,2}:\d{2}~', trim($match[1]))
            ) {
                return trim($match[1]);
            }
        }

        return null;
    }
}
