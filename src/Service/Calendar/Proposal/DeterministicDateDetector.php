<?php

declare(strict_types=1);

namespace App\Service\Calendar\Proposal;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Stage two: the dates a person can write down without ambiguity, read exactly.
 *
 * Explicit and semi-explicit forms in German and English, because this user's
 * mail is both and a parser that handled one of them would be wrong half the
 * time rather than right half the time. `04.08.2026 um 14 Uhr`,
 * `4. August 2026, 14:00`, `2026-08-04 14:00`, `Samstag um 15 Uhr`,
 * `Saturday at 3pm`, `tomorrow at 9`, `next Tuesday 10:30`, with durations where
 * the mail states one — `2 Stunden`, `2 hours`, `90 Minuten`.
 *
 * Three rules shape all of it, and each exists because the alternative fails
 * quietly rather than loudly.
 *
 * **A date and a time, in the same sentence, close together.** A date on its own
 * is refused — "gültig bis 31.12.2026" in a footer is a date, and an all-day
 * entry guessed from a legal notice is precisely the noise that makes people
 * turn a feature like this off. A time on its own is refused for the same
 * reason with the day missing instead of the hour. And the two have to be near
 * each other: a paragraph mentioning a deadline in its first line and an office
 * closing hour in its last states two unrelated facts, not one appointment.
 *
 * **The first sentence that yields both wins.** Not the first date in the mail:
 * signature blocks, copyright years and unsubscribe footers all carry dates, and
 * none of them carries one next to a time. Reading order is the only ordering
 * prose offers, and the appointment is stated before the boilerplate in every
 * mail anybody actually sends.
 *
 * **Relative forms resolve against the message, never against now().** Every
 * date built here starts from ProposalContext::$anchor. This class never
 * constructs a "now" of any kind, and that absence is the point: "Saturday" in a
 * mail sent on a Friday has to still mean that Saturday when a backfill re-reads
 * it a year later.
 *
 * What it deliberately refuses:
 *
 *   A two-digit year — `04.08.26`. Twenty-six is a day in some hands and a year
 *   in others, and the century is a further guess on top.
 *   A day and month with no year at all in numeric form — `04.08.` — for the
 *   same reason: it is a fragment, not a date. (Written out, `4. August`, it is
 *   accepted, because the month name removes the day/month ambiguity and the
 *   year then resolves forward from the message.)
 *   A slashed date the user's own locale reads as impossible — `13/25/2026`.
 *   The locale decides which number is the month, and when that reading is not
 *   a real date the answer is no, not the other reading. Trying the other way
 *   round is right nine times and a week out on the tenth, silently.
 *   A bare hour that no `um`/`at` introduces. "Room 3, seats 12" is not half
 *   past midday.
 *
 * Hours are read on a 24-hour clock unless a meridiem says otherwise, so
 * `tomorrow at 9` is nine in the morning. Deciding that a lone small number
 * "obviously" means the evening is exactly the kind of inference this stage
 * exists not to make; the model in stage three may make it, and it will be
 * marked as having done so.
 */
final readonly class DeterministicDateDetector implements ProposalDetectorInterface
{
    /**
     * What this detector calls itself on a proposal row.
     *
     * A constant because the name is written in more than one place the day a
     * second detector exists — the column, the reports that compare them — and
     * a string typed twice is a comparison that silently matches nothing.
     */
    public const string NAME = 'prose';

    /**
     * The mail named a calendar date: a number-and-month, or three numbers with
     * a full year. Nothing here is certain enough for more — the sentence may
     * still be quoting somebody else's meeting — but the date itself is not in
     * question.
     */
    private const int CONFIDENCE_STATED_DATE = 70;

    /**
     * The mail named a weekday or said "tomorrow", and the date came from
     * arithmetic on when the mail was sent. Correct whenever the writer meant
     * the obvious thing, and the writer is the one variable nobody can check.
     */
    private const int CONFIDENCE_RELATIVE_DATE = 55;

    /** An appointment whose length nobody stated. Same nominal hour the ICS extractor uses. */
    private const int DEFAULT_DURATION_MINUTES = 60;

    /**
     * A stated duration outside this range is not this meeting's length —
     * "5 minutes" is a figure of speech and "48 hours" is a deadline. Both are
     * ignored in favour of the nominal hour rather than taken literally.
     */
    private const int MIN_DURATION_MINUTES = 10;
    private const int MAX_DURATION_MINUTES = 720;

    /**
     * How far apart, in characters, a date and a time may sit and still be
     * about the same appointment. "04.08.2026 um 14 Uhr" is zero; a paragraph
     * naming a deadline at one end and an opening hour at the other is not one
     * appointment however grammatical the sentence between them is.
     */
    private const int MAX_DATE_TIME_DISTANCE = 60;

    /** The quoted evidence is a line on a card, not the paragraph it came from. */
    private const int MAX_SENTENCE_CHARS = 300;

    /**
     * Lines that are somebody else's mail rather than this one's.
     *
     * Found by running the parser over a real mailbox: fifty-six of the
     * fifty-seven messages that passed the gate yielded a date, and every one
     * of them came from a reply's own furniture — "On Thu, Jul 2, 2026 at 12:56
     * PM Paul Lützner <…> wrote:" and "Sent: Friday, July 24, 2026 3:58 PM".
     * Those are dates, correctly parsed, describing when the message being
     * quoted was sent. The horizon rule happens to refuse nearly all of them,
     * because a quoted message is older than the one quoting it — but relying
     * on that is relying on an accident, and the one that slipped through would
     * be an appointment nobody arranged.
     *
     * `Date:`/`Datum:` is deliberately NOT in the list, though it appears in
     * the same forwarded header block. German business mail states an
     * appointment as "Datum: 4. August 2026, 14 Uhr", and refusing that costs
     * more than the forwarded header it would also catch — which is, again,
     * always in the past.
     *
     * @var list<string>
     */
    private const array QUOTATION = [
        // A quoted line, in every client that has ever existed.
        '~^\s*>~u',
        // "On <date> … wrote" / "Am <Datum> schrieb <Name>:" — the German verb
        // comes before the name it introduces, so anchoring on the end of the
        // line reads "Am 06.08.2026 um 12:56 schrieb Paul:" as an appointment.
        '~^\s*(?:on|am)\s+.{0,200}\b(?:wrote|schrieb)\b~iu',
        // The same attribution when the verb wrapped onto the next line.
        '~\b(?:wrote|schrieb)\s*:\s*$~iu',
        '~^\s*(?:on|am)\s+.{0,200}<[^>]+@[^>]+>~iu',
        // The header block a forward echoes.
        '~^\s*(?:from|von|sent|gesendet|to|an|cc|subject|betreff|reply-to)\s*:~iu',
        // And the separator above it.
        '~^\s*-{2,}\s*(?:original|forwarded|urspr|weitergeleitete)~iu',
    ];

    /**
     * Month names, spelled and abbreviated, in both languages.
     *
     * Written out rather than derived from IntlDateFormatter: the parse must
     * give the same answer on every machine, and the ICU data behind a
     * formatter is a system package that differs between the container and a
     * developer's laptop.
     *
     * @var array<string,int>
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

    /**
     * Weekday names to their ISO-8601 number, Monday = 1.
     *
     * @var array<string,int>
     */
    private const array WEEKDAYS = [
        'montag' => 1, 'monday' => 1, 'mon' => 1,
        'dienstag' => 2, 'tuesday' => 2, 'tue' => 2, 'tues' => 2,
        'mittwoch' => 3, 'wednesday' => 3, 'wed' => 3,
        'donnerstag' => 4, 'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4,
        'freitag' => 5, 'friday' => 5, 'fri' => 5,
        'samstag' => 6, 'sonnabend' => 6, 'saturday' => 6, 'sat' => 6,
        'sonntag' => 7, 'sunday' => 7, 'sun' => 7,
    ];

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * The highest, and the only one for now. A model-backed detector belongs
     * below this: it should be asked only about what arithmetic could not read.
     */
    public function priority(): int
    {
        return 100;
    }

    public function detect(ProposalContext $context): ?DetectedDate
    {
        // Read once over the whole body rather than per sentence, because a
        // stated length is as often on a line of its own as beside the time —
        // "Termin wie vereinbart: 04.08.2026 um 14 Uhr" and "Zeitrahmen: 2
        // Stunden" are two lines of the same mail.
        $minutes = $this->durationIn($context->text) ?? self::DEFAULT_DURATION_MINUTES;

        foreach ($this->sentences($context->text) as $sentence) {
            $found = $this->read($sentence, $context, $minutes);

            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function read(string $sentence, ProposalContext $context, int $minutes): ?DetectedDate
    {
        $date = $this->dateIn($sentence, $context);

        if (null === $date) {
            return null;
        }

        $time = $this->timeIn($sentence);

        if (null === $time) {
            return null;
        }

        if (self::MAX_DATE_TIME_DISTANCE < $this->distance($date, $time)) {
            return null;
        }

        $startsAt = $date['date']
            ->setTime($time['hour'], $time['minute'])
            ->setTimezone(new DateTimeZone('UTC'));

        // Only a weekday rolls forward. "Samstag um 15 Uhr" written at six in
        // the evening on a Saturday means the one coming, because nobody
        // arranges a meeting for three hours ago. "heute um 9 Uhr" does not
        // roll: it names today, and a today that has passed is a refusal for
        // EventProposer to make rather than a date for this to invent.
        if (true === $date['rollsForward'] && $startsAt < $context->anchor) {
            $startsAt = $startsAt->modify('+7 days');
        }

        return new DetectedDate(
            startsAt:   $startsAt,
            endsAt:     $startsAt->modify(sprintf('+%d minutes', $minutes)),
            sentence:   $this->clamp($sentence, $date['offset']),
            confidence: true === $date['isRelative']
                ? self::CONFIDENCE_RELATIVE_DATE
                : self::CONFIDENCE_STATED_DATE,
        );
    }

    /**
     * The first date this sentence states, as local midnight in the user's zone.
     *
     * Patterns are tried in order of how little they leave to interpretation,
     * not in the order they appear in the text: "Freitag, den 04.09.2026" says
     * the same thing twice and the numeric half is the half that cannot be
     * misread. Both readings agree in a well-formed sentence, and where they
     * disagree the writer made a mistake this is in no position to resolve.
     *
     * @return array{date: DateTimeImmutable, offset: int, length: int, isRelative: bool, rollsForward: bool}|null
     */
    private function dateIn(string $sentence, ProposalContext $context): ?array
    {
        $local  = $context->anchor->setTimezone($context->zone)->setTime(0, 0);
        $months = implode('|', array_keys(self::MONTHS));
        $days   = implode('|', array_keys(self::WEEKDAYS));

        // 2026-08-04. The one form no locale can read two ways.
        $match = $this->firstMatch('~\b(\d{4})-(\d{1,2})-(\d{1,2})\b~u', $sentence);

        if (null !== $match) {
            return $this->stated($local, (int) $match[3][0], (int) $match[2][0], (int) $match[1][0], $match);
        }

        // 04.08.2026. Dots are the German convention and mean day first
        // wherever they appear; the four-digit year is required, which is what
        // refuses 04.08.26 and the bare 04.08. fragment.
        $match = $this->firstMatch('~\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b~u', $sentence);

        if (null !== $match) {
            return $this->stated($local, (int) $match[1][0], (int) $match[2][0], (int) $match[3][0], $match);
        }

        // 04/08/2026 — genuinely ambiguous, so the user's own locale decides
        // and nothing about the content does. A reading that is not a real date
        // is refused rather than flipped; see the class docblock.
        $match = $this->firstMatch('~\b(\d{1,2})[/-](\d{1,2})[/-](\d{4})\b~u', $sentence);

        if (null !== $match) {
            $isGerman = 'de' === $context->language;

            return $this->stated(
                $local,
                (int) ($isGerman ? $match[1][0] : $match[2][0]),
                (int) ($isGerman ? $match[2][0] : $match[1][0]),
                (int) $match[3][0],
                $match,
            );
        }

        // 4. August 2026, 4 August, 4th August 2026.
        $match = $this->firstMatch(
            sprintf('~\b(\d{1,2})(?:\.|st|nd|rd|th)?\s*(%s)\b(?:\s*,?\s*(\d{4}))?~iu', $months),
            $sentence,
        );

        if (null !== $match) {
            return $this->stated(
                $local,
                (int) $match[1][0],
                self::MONTHS[$this->key($match[2][0])],
                $this->year($match[3][0] ?? '', $local, (int) $match[1][0], self::MONTHS[$this->key($match[2][0])]),
                $match,
            );
        }

        // August 4, 2026 / August 4th.
        $match = $this->firstMatch(
            sprintf('~\b(%s)\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b(?:\s*,?\s*(\d{4}))?~iu', $months),
            $sentence,
        );

        if (null !== $match) {
            return $this->stated(
                $local,
                (int) $match[2][0],
                self::MONTHS[$this->key($match[1][0])],
                $this->year($match[3][0] ?? '', $local, (int) $match[2][0], self::MONTHS[$this->key($match[1][0])]),
                $match,
            );
        }

        // übermorgen before morgen, always: PCRE's \b is ASCII-only here, so
        // the boundary between "ü" and "m" is a real one and `\bmorgen\b`
        // matches inside "übermorgen" — which would move every day-after-
        // tomorrow appointment twenty-four hours earlier.
        $match = $this->firstMatch('~übermorgen~iu', $sentence);

        if (null !== $match) {
            return $this->relative($local->modify('+2 days'), $match, rollsForward: false);
        }

        $match = $this->firstMatch('~\b(?:morgen|tomorrow)\b~iu', $sentence);

        if (null !== $match) {
            return $this->relative($local->modify('+1 day'), $match, rollsForward: false);
        }

        $match = $this->firstMatch('~\b(?:heute|today|tonight)\b~iu', $sentence);

        if (null !== $match) {
            return $this->relative($local, $match, rollsForward: false);
        }

        // "nächsten Dienstag" / "next Tuesday": the first such weekday strictly
        // after the day the mail was sent. On any day but that weekday it is
        // the same answer as the bare name; on that weekday it is the only
        // reading available, since "next Tuesday" cannot mean today.
        $match = $this->firstMatch(
            sprintf('~\b(?:n(?:ä|ae)chste[nrs]?|kommende[nrs]?|next)\s+(%s)\b~iu', $days),
            $sentence,
        );

        if (null !== $match) {
            return $this->relative(
                $this->weekday($local, self::WEEKDAYS[$this->key($match[1][0])], strictlyAfter: true),
                $match,
                rollsForward: true,
            );
        }

        $match = $this->firstMatch(sprintf('~\b(%s)\b~iu', $days), $sentence);

        if (null !== $match) {
            return $this->relative(
                $this->weekday($local, self::WEEKDAYS[$this->key($match[1][0])], strictlyAfter: false),
                $match,
                rollsForward: true,
            );
        }

        return null;
    }

    /**
     * The first time of day this sentence states.
     *
     * Ordered so that the forms carrying their own units are read before the
     * ones that depend on a preposition. The dotted German form insists on
     * "Uhr" following it, because `14.30` and `04.08` are the same shape and
     * one of them is half a date.
     *
     * @return array{hour: int, minute: int, offset: int, length: int}|null
     */
    private function timeIn(string $sentence): ?array
    {
        // 14:00, 10:30, 3:30 pm.
        $match = $this->firstMatch('~\b(\d{1,2}):(\d{2})\s*(am|pm|a\.m\.|p\.m\.)?~iu', $sentence);

        if (null !== $match) {
            return $this->time(
                $this->meridiem((int) $match[1][0], $match[3][0] ?? ''),
                (int) $match[2][0],
                $match,
            );
        }

        // 14.30 Uhr.
        $match = $this->firstMatch('~\b(\d{1,2})\.(\d{2})\s*uhr\b~iu', $sentence);

        if (null !== $match) {
            return $this->time((int) $match[1][0], (int) $match[2][0], $match);
        }

        // 3pm, 3 p.m.
        $match = $this->firstMatch('~\b(\d{1,2})\s*(am|pm|a\.m\.|p\.m\.)~iu', $sentence);

        if (null !== $match) {
            return $this->time($this->meridiem((int) $match[1][0], $match[2][0]), 0, $match);
        }

        // 14 Uhr, 14 Uhr 30.
        $match = $this->firstMatch('~\b(\d{1,2})\s*uhr(?:\s*(\d{2}))?~iu', $sentence);

        if (null !== $match) {
            return $this->time((int) $match[1][0], (int) ($match[2][0] ?? 0), $match);
        }

        // "um 9", "at 9", "gegen 18". The preposition is what makes a lone
        // number a time rather than a house number or a seat count.
        $match = $this->firstMatch('~\b(?:um|at|ab|gegen|around)\s+(\d{1,2})\b(?![:.]\d)~iu', $sentence);

        if (null !== $match) {
            return $this->time((int) $match[1][0], 0, $match);
        }

        return null;
    }

    /**
     * A length the mail stated, in minutes, or null where it stated none.
     *
     * Whichever of the three forms appears first in the text, so a mail that
     * says "2 Stunden" in the body and "30 Minuten Puffer" in a postscript
     * takes the first as the appointment and the second as the aside.
     */
    private function durationIn(string $text): ?int
    {
        $candidates = [];

        // "2 Stunden", "1,5 hours", "2 hrs".
        $match = $this->firstMatch(
            '~\b(\d{1,3})(?:[.,](\d))?\s*(?:stunden|stunde|std\.?|hours|hour|hrs|hr)\b~iu',
            $text,
        );

        if (null !== $match) {
            $candidates[$match[0][1]] = (int) round(
                60 * ((float) $match[1][0] + (float) ('' === ($match[2][0] ?? '') ? 0 : '0.' . $match[2][0])),
            );
        }

        // "90 Minuten", "45 mins".
        $match = $this->firstMatch('~\b(\d{1,3})\s*(?:minuten|minute|min\.?|minutes|mins)\b~iu', $text);

        if (null !== $match) {
            $candidates[$match[0][1]] = (int) $match[1][0];
        }

        // "eine Stunde", "an hour".
        $match = $this->firstMatch('~\b(?:eine|einer|an|one)\s+(?:stunde|hour)\b~iu', $text);

        if (null !== $match) {
            $candidates[$match[0][1]] = 60;
        }

        if ([] === $candidates) {
            return null;
        }

        ksort($candidates);

        $minutes = (int) reset($candidates);

        return self::MIN_DURATION_MINUTES <= $minutes && $minutes <= self::MAX_DURATION_MINUTES
            ? $minutes
            : null;
    }

    /**
     * Sentences, near enough, and lines wherever the mail used them.
     *
     * A line break is a stronger separator than a full stop in mail — the
     * German example states the appointment on one line and its length on the
     * next — and the full stop needs whitespace after it, so that "04.08.2026"
     * survives as one token.
     *
     * A full stop directly after a digit does not end a sentence, and that
     * exception is not cosmetic: German ordinals carry one, so "4. August 2026,
     * 14:00 Uhr" was being cut into "4." and "August 2026, 14:00 Uhr" — a
     * fragment with no month and a month with no day, neither of which is a
     * date. Every German written-out date failed on it and nothing else did.
     *
     * @return list<string>
     */
    private function sentences(string $text): array
    {
        $parts = preg_split('~(?:\r?\n)+|(?<=[.!?;])(?<!\d\.)\s+~u', $text) ?: [];

        $sentences = [];

        foreach ($parts as $part) {
            $trimmed = trim($part);

            if ('' !== $trimmed && false === $this->isQuotation($trimmed)) {
                $sentences[] = $trimmed;
            }
        }

        return $sentences;
    }

    /** Somebody else's mail, quoted below this one. See QUOTATION. */
    private function isQuotation(string $sentence): bool
    {
        foreach (self::QUOTATION as $pattern) {
            if (1 === preg_match($pattern, $sentence)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{0: string, 1: int}> $match
     *
     * @return array{date: DateTimeImmutable, offset: int, length: int, isRelative: bool, rollsForward: bool}|null
     */
    private function stated(DateTimeImmutable $local, int $day, int $month, int $year, array $match): ?array
    {
        if (false === checkdate($month, $day, $year)) {
            return null;
        }

        return [
            'date'         => $local->setDate($year, $month, $day),
            'offset'       => $match[0][1],
            'length'       => strlen($match[0][0]),
            'isRelative'   => false,
            'rollsForward' => false,
        ];
    }

    /**
     * @param array<int, array{0: string, 1: int}> $match
     *
     * @return array{date: DateTimeImmutable, offset: int, length: int, isRelative: bool, rollsForward: bool}
     */
    private function relative(DateTimeImmutable $date, array $match, bool $rollsForward): array
    {
        return [
            'date'         => $date,
            'offset'       => $match[0][1],
            'length'       => strlen($match[0][0]),
            'isRelative'   => true,
            'rollsForward' => $rollsForward,
        ];
    }

    /**
     * The next occurrence of a weekday, counted from the day the mail was sent.
     *
     * On or after that day for a bare name — a mail sent on Saturday saying
     * "Samstag" means today — and strictly after it when the writer said
     * "next", which cannot mean the day they were writing on.
     */
    private function weekday(DateTimeImmutable $local, int $isoDay, bool $strictlyAfter): DateTimeImmutable
    {
        $delta = ($isoDay - (int) $local->format('N') + 7) % 7;

        if (0 === $delta && true === $strictlyAfter) {
            $delta = 7;
        }

        return 0 === $delta ? $local : $local->modify(sprintf('+%d days', $delta));
    }

    /**
     * The year a month-name date left out.
     *
     * Forward from the message: "4. August" written in July means this year,
     * and written in December means next. Never backward — a mail arranging a
     * date in the past is a mail this feature has no business reading.
     */
    private function year(string $stated, DateTimeImmutable $local, int $day, int $month): int
    {
        if ('' !== $stated) {
            return (int) $stated;
        }

        $year = (int) $local->format('Y');

        return true === checkdate($month, $day, $year) && $local->setDate($year, $month, $day) >= $local
            ? $year
            : $year + 1;
    }

    /**
     * @param array<int, array{0: string, 1: int}> $match
     *
     * @return array{hour: int, minute: int, offset: int, length: int}|null
     */
    private function time(int $hour, int $minute, array $match): ?array
    {
        if (0 > $hour || 23 < $hour || 0 > $minute || 59 < $minute) {
            return null;
        }

        return [
            'hour'   => $hour,
            'minute' => $minute,
            'offset' => $match[0][1],
            'length' => strlen($match[0][0]),
        ];
    }

    /** 12am is midnight and 12pm is noon; every other hour just shifts. */
    private function meridiem(int $hour, string $suffix): int
    {
        $suffix = str_replace('.', '', mb_strtolower(trim($suffix)));

        if ('' === $suffix || 12 < $hour) {
            return $hour;
        }

        if ('pm' === $suffix) {
            return 12 === $hour ? 12 : $hour + 12;
        }

        return 12 === $hour ? 0 : $hour;
    }

    /**
     * @param array{date: DateTimeImmutable, offset: int, length: int, isRelative: bool, rollsForward: bool} $date
     * @param array{hour: int, minute: int, offset: int, length: int}                                        $time
     */
    private function distance(array $date, array $time): int
    {
        if ($date['offset'] <= $time['offset']) {
            return max(0, $time['offset'] - ($date['offset'] + $date['length']));
        }

        return max(0, $date['offset'] - ($time['offset'] + $time['length']));
    }

    /**
     * The evidence, trimmed to something a card can show.
     *
     * Windowed around the date rather than cut from the front: a long paragraph
     * clipped at three hundred characters from its start is quite likely to
     * lose the very words the quote exists to show.
     */
    private function clamp(string $sentence, int $offset): string
    {
        if (mb_strlen($sentence) <= self::MAX_SENTENCE_CHARS) {
            return $sentence;
        }

        // Byte offset to character offset, since the match ran on bytes and
        // everything below counts characters.
        $at     = mb_strlen(substr($sentence, 0, $offset));
        $start  = max(0, $at - intdiv(self::MAX_SENTENCE_CHARS, 2));
        $prefix = 0 === $start ? '' : '…';

        return $prefix . trim(mb_substr($sentence, $start, self::MAX_SENTENCE_CHARS)) . '…';
    }

    /**
     * The first match with its byte offsets, or null.
     *
     * @return array<int, array{0: string, 1: int}>|null
     */
    private function firstMatch(string $pattern, string $subject): ?array
    {
        if (1 !== preg_match($pattern, $subject, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return $matches;
    }

    /** Lookup keys in the month and weekday tables are lower case and unaccented-safe. */
    private function key(string $token): string
    {
        return mb_strtolower(rtrim(trim($token), '.'));
    }
}
