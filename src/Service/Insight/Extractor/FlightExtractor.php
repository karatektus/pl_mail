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
 * Flights: designators, booking codes and routes read out of airline and
 * travel-agent mail, deterministically.
 *
 * The designator shape — two characters and up to four digits — is so short
 * that prose matches it constantly ("no 42", "on 3"), which is why the shape
 * alone admits nothing: the two-character prefix has to be a real airline code
 * from the allowlist below. That trades recall for precision on purpose. A
 * missed exotic airline is a card that fails to appear; an invented flight is
 * a card that says the user is flying somewhere they are not.
 *
 * Everything beyond the designator is optional and stays null when unstated:
 * a PNR only counts next to a word that announces one, a route only as an
 * explicit IATA pair, and a departure only as an explicit date near the
 * designator. A flight card with just "LH 1234" on it is honest; see
 * InsightExtractorInterface on returning nothing rather than probably-
 * something.
 */
final readonly class FlightExtractor implements InsightExtractorInterface
{
    /**
     * Airlines and the agents that book them — matched on the suffix so
     * booking@mail.lufthansa.com counts.
     *
     * @var list<string>
     */
    private const array AIRLINE_DOMAINS = [
        'lufthansa.com', 'swiss.com', 'austrian.com', 'eurowings.com',
        'ryanair.com', 'easyjet.com', 'klm.com', 'airfrance.com',
        'united.com', 'delta.com', 'aa.com',
        'opodo.de', 'expedia.de', 'expedia.com', 'kayak.com', 'check24.de',
    ];

    /**
     * Subject phrases that make a mail about a flight, EN and DE, lowercase.
     * A hint admits the mail; extract() still has to find a designator with
     * an allowlisted airline code before anything is emitted.
     *
     * @var list<string>
     */
    private const array SUBJECT_HINTS = [
        'flight confirmation', 'booking confirmation', 'buchungsbestätigung',
        'ihr flug', 'your flight', 'boarding pass', 'bordkarte',
        'itinerary', 'reiseplan',
    ];

    /**
     * IATA airline codes this extractor believes in. The filter that makes the
     * designator regex safe: "ZZ 123" on a licence plate matches the shape,
     * but ZZ flies nothing.
     *
     * @var list<string>
     */
    private const array AIRLINE_CODES = [
        'LH', 'LX', 'OS', 'EW', 'FR', 'U2', 'KL', 'AF', 'UA', 'DL', 'AA',
        'BA', 'IB', 'TK', 'EK', 'QR', 'SQ', 'X3', 'DE', 'SN', 'AZ', 'VY',
        'W6', 'PC',
    ];

    /** An itinerary has legs; three covers outbound-stopover-return. */
    private const int MAX_DRAFTS = 3;

    /**
     * How far, in characters, a date or time may sit from the designator and
     * still describe that leg's departure. Wide enough for "EW 9463 on
     * 2027-03-14 at 07:25", narrow enough that leg one's date cannot bleed
     * into leg two three lines later.
     */
    private const int NEARBY = 120;

    /**
     * How far past a PNR context word the six-character token may sit. The
     * guard that keeps every capitalized six-letter word in a mail from
     * becoming a booking code.
     */
    private const int PNR_WINDOW = 40;

    /**
     * Month names, spelled and abbreviated, in both languages — written out
     * rather than derived from ICU for the same reason
     * DeterministicDateDetector writes them out: the parse must give the same
     * answer on every machine.
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
        return 'flight';
    }

    public function icon(): string
    {
        return 'fa-solid fa-plane';
    }

    public function priority(): int
    {
        return 100;
    }

    public function supports(Message $message): bool
    {
        $domain = $this->senderDomain($message);

        if (null !== $domain) {
            foreach (self::AIRLINE_DOMAINS as $known) {
                if ($domain === $known || true === str_ends_with($domain, '.' . $known)) {
                    return true;
                }
            }
        }

        $subject = mb_strtolower((string) $message->subject);

        foreach (self::SUBJECT_HINTS as $hint) {
            if (true === str_contains($subject, $hint)) {
                return true;
            }
        }

        return false;
    }

    public function extract(Message $message): array
    {
        $subject = trim((string) $message->subject);
        $body = trim((string) $message->bodyText);

        // Subject and body as one text: airlines put the designator in the
        // subject and the itinerary in the body, boarding-pass mails do the
        // reverse, and offsets into the concatenation serve both.
        $text = trim($subject . "\n" . $body);

        if ('' === $text) {
            return [];
        }

        $legs = $this->designatorsIn($text);

        if ([] === $legs) {
            return [];
        }

        // One booking code covers every leg of the itinerary it books.
        $pnr = $this->pnrIn($text);
        $routes = $this->routesIn($text);
        $dates = $this->datesIn($text, $message->receivedAt);
        $times = $this->timesIn($text);

        $drafts = [];

        foreach (array_slice($legs, 0, self::MAX_DRAFTS) as $leg) {
            // Nearest-by-distance rather than first-in-mail, because a two-leg
            // itinerary states two dates and two routes and each leg must take
            // its own — reading order would hand leg one's date to both.
            $route = $this->nearest($routes, $leg['offset'], $leg['length'], null, $text);
            $date = $this->nearest($dates, $leg['offset'], $leg['length'], self::NEARBY, $text);

            $happensAt = null;
            $departsAtText = null;

            if (null !== $date) {
                $time = $this->nearest($times, $leg['offset'], $leg['length'], self::NEARBY, $text);

                // The date parses to noon; a stated time replaces that
                // placeholder, an unstated one keeps it — a flight on a known
                // day at an unknown hour is still worth a dated card.
                $happensAt = null === $time
                    ? $date['date']
                    : $date['date']->setTime($time['hour'], $time['minute']);

                $departsAtText = null === $time ? $date['raw'] : $date['raw'] . ' ' . $time['raw'];
            }

            $title = $leg['code'] . ' ' . $leg['digits'];

            if (null !== $route) {
                $title .= ' · ' . $route['from'] . '–' . $route['to'];
            }

            $drafts[] = new InsightDraft(
                kind: InsightKind::Flight,
                title: $title,
                // The date is part of the identity: LH 1234 flies every day,
                // and Tuesday's flight is not Wednesday's. Undated mails about
                // the same designator collapse onto one card, which is the
                // best that can be done without a date to tell them apart.
                dedupeKey: $leg['number'] . (null === $happensAt ? '' : '@' . $happensAt->format('Y-m-d')),
                payload: [
                    'flightNumber'  => $leg['number'],
                    'pnr'           => $pnr,
                    'from'          => $route['from'] ?? null,
                    'to'            => $route['to'] ?? null,
                    'departsAtText' => $departsAtText,
                ],
                happensAt: $happensAt,
            );
        }

        return $drafts;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Flight designators whose airline code the allowlist vouches for, in
     * reading order, deduplicated — a check-in mail names the same flight
     * three times and flies it once.
     *
     * @return list<array{code: string, digits: string, number: string, offset: int, length: int}>
     */
    private function designatorsIn(string $text): array
    {
        preg_match_all(
            '~\b([A-Z]{2}|[A-Z]\d|\d[A-Z])\s?(\d{1,4})\b~',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );

        $legs = [];

        foreach ($matches as $match) {
            $code = $match[1][0];

            if (false === in_array($code, self::AIRLINE_CODES, true)) {
                continue;
            }

            $number = $code . $match[2][0];

            foreach ($legs as $leg) {
                if ($leg['number'] === $number) {
                    continue 2;
                }
            }

            $legs[] = [
                'code'   => $code,
                'digits' => $match[2][0],
                'number' => $number,
                'offset' => $match[0][1],
                'length' => strlen($match[0][0]),
            ];
        }

        return $legs;
    }

    /**
     * The booking code, only where a word announces one. Six uppercase
     * alphanumerics is the shape of half the tokens in any mail — "SUMMER" in
     * a promo line included — so the shape counts solely within PNR_WINDOW
     * after "Buchungscode", "booking reference" and kin.
     */
    private function pnrIn(string $text): ?string
    {
        preg_match_all(
            '~booking\s+reference|buchungscode|pnr|record\s+locator|buchungsnummer|confirmation\s+code~i',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[0] as [$word, $offset]) {
            $window = substr($text, $offset + strlen($word), self::PNR_WINDOW);

            if (1 === preg_match('~\b([A-Z0-9]{6})\b~', $window, $hit)) {
                return $hit[1];
            }
        }

        return null;
    }

    /**
     * Explicit IATA pairs — FRA–JFK, CGN - PMI, TXL→MUC. Only the pair form:
     * a bare three-letter word is an acronym far more often than an airport.
     *
     * @return list<array{from: string, to: string, offset: int, length: int}>
     */
    private function routesIn(string $text): array
    {
        preg_match_all(
            '~\b([A-Z]{3})\s?[-–→]\s?([A-Z]{3})\b~u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );

        $routes = [];

        foreach ($matches as $match) {
            $routes[] = [
                'from'   => $match[1][0],
                'to'     => $match[2][0],
                'offset' => $match[0][1],
                'length' => strlen($match[0][0]),
            ];
        }

        return $routes;
    }

    /**
     * Every explicit date in the text, resolved to a noon-UTC instant, with
     * where it was found — the caller picks the one nearest each leg.
     *
     * @return list<array{date: DateTimeImmutable, raw: string, offset: int, length: int}>
     */
    private function datesIn(string $text, ?DateTimeImmutable $receivedAt): array
    {
        $months = implode('|', array_keys(self::MONTHS));

        $found = [];

        // 24.12.2026 — day first, full year required.
        preg_match_all('~\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b~', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            $found[] = [$match, $this->dateFrom((int) $match[1][0], (int) $match[2][0], (int) $match[3][0], $receivedAt)];
        }

        // 2026-12-24.
        preg_match_all('~\b(\d{4})-(\d{1,2})-(\d{1,2})\b~', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            $found[] = [$match, $this->dateFrom((int) $match[3][0], (int) $match[2][0], (int) $match[1][0], $receivedAt)];
        }

        // 24. Dezember [2026] — the month name is what lets the year be
        // optional; the numeric forms above insist on it.
        preg_match_all(sprintf('~\b(\d{1,2})\.?\s*(%s)\b(?:\s*,?\s*(\d{4}))?~iu', $months), $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            $found[] = [$match, $this->dateFrom(
                (int) $match[1][0],
                self::MONTHS[$this->monthKey($match[2][0])],
                '' === ($match[3][0] ?? '') ? null : (int) $match[3][0],
                $receivedAt,
            )];
        }

        // December 24[, 2026].
        preg_match_all(sprintf('~\b(%s)\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b(?:\s*,?\s*(\d{4}))?~iu', $months), $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            $found[] = [$match, $this->dateFrom(
                (int) $match[2][0],
                self::MONTHS[$this->monthKey($match[1][0])],
                '' === ($match[3][0] ?? '') ? null : (int) $match[3][0],
                $receivedAt,
            )];
        }

        $dates = [];

        foreach ($found as [$match, $date]) {
            if (null !== $date) {
                $dates[] = [
                    'date'   => $date,
                    'raw'    => $match[0][0],
                    'offset' => $match[0][1],
                    'length' => strlen($match[0][0]),
                ];
            }
        }

        return $dates;
    }

    /**
     * Every plausible clock time, with where it was found.
     *
     * @return list<array{hour: int, minute: int, raw: string, offset: int, length: int}>
     */
    private function timesIn(string $text): array
    {
        preg_match_all('~\b(\d{1,2}):(\d{2})\b~', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $times = [];

        foreach ($matches as $match) {
            $hour = (int) $match[1][0];
            $minute = (int) $match[2][0];

            if (23 < $hour || 59 < $minute) {
                continue;
            }

            $times[] = [
                'hour'   => $hour,
                'minute' => $minute,
                'raw'    => $match[0][0],
                'offset' => $match[0][1],
                'length' => strlen($match[0][0]),
            ];
        }

        return $times;
    }

    /**
     * The candidate that belongs to this leg: same line first, then closest by
     * character gap; null when none sits within $maxDistance (null: no limit —
     * a route belongs to its nearest leg however the mail is laid out).
     *
     * The line preference is not cosmetic. Itineraries are line-per-leg —
     * "Return: EW 9464 … 11:40, PMI–CGN" — and raw distance would hand the
     * return leg the OUTBOUND route, because the previous line's tail sits
     * nine characters above while the leg's own route waits at the end of its
     * own line. A line break is a stronger separator than the character count
     * says, exactly as DeterministicDateDetector treats it in sentences().
     *
     * @template T of array{offset: int, length: int}
     *
     * @param list<T> $candidates
     *
     * @return T|null
     */
    private function nearest(array $candidates, int $offset, int $length, ?int $maxDistance, string $text): ?array
    {
        $sameLine = [];
        $anywhere = [];

        foreach ($candidates as $candidate) {
            $distance = $this->distance($offset, $length, $candidate['offset'], $candidate['length']);

            if (null !== $maxDistance && $distance > $maxDistance) {
                continue;
            }

            $anywhere[] = [$candidate, $distance];

            if (true === $this->onOneLine($text, $offset, $length, $candidate['offset'], $candidate['length'])) {
                $sameLine[] = [$candidate, $distance];
            }
        }

        $pool = [] !== $sameLine ? $sameLine : $anywhere;

        $best = null;
        $bestDistance = null;

        foreach ($pool as [$candidate, $distance]) {
            if (null === $bestDistance || $distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /** No line break between the two spans — they share a line of the mail. */
    private function onOneLine(string $text, int $aStart, int $aLength, int $bStart, int $bLength): bool
    {
        $from = min($aStart + $aLength, $bStart + $bLength);
        $to = max($aStart, $bStart);

        if ($from >= $to) {
            return true;
        }

        return false === str_contains(substr($text, $from, $to - $from), "\n");
    }

    /** Character gap between two spans; zero when they touch or overlap. */
    private function distance(int $aStart, int $aLength, int $bStart, int $bLength): int
    {
        if ($aStart + $aLength <= $bStart) {
            return $bStart - ($aStart + $aLength);
        }

        if ($bStart + $bLength <= $aStart) {
            return $aStart - ($bStart + $bLength);
        }

        return 0;
    }

    /**
     * A calendar day as a noon-UTC instant, with a missing year resolved
     * against the MAIL, never the clock — the same rule ParcelExtractor
     * follows, for the same backfill reason. receivedAt's own year, unless
     * that puts the date more than two months behind the mail; then it means
     * next year. No year and no receivedAt is a refusal, not a guess.
     */
    private function dateFrom(int $day, int $month, ?int $year, ?DateTimeImmutable $receivedAt): ?DateTimeImmutable
    {
        if (null === $year) {
            if (null === $receivedAt) {
                return null;
            }

            $year = (int) $receivedAt->format('Y');

            if (false === checkdate($month, $day, $year)) {
                return null;
            }

            if ($this->noon($day, $month, $year) < $receivedAt->modify('-2 months')) {
                ++$year;
            }
        }

        if (false === checkdate($month, $day, $year)) {
            return null;
        }

        return $this->noon($day, $month, $year);
    }

    private function noon(int $day, int $month, int $year): DateTimeImmutable
    {
        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d 12:00:00', $year, $month, $day),
            new DateTimeZone('UTC'),
        );
    }

    private function senderDomain(Message $message): ?string
    {
        $address = mb_strtolower(trim((string) $message->fromAddress));
        $at = strrpos($address, '@');

        if (false === $at) {
            return null;
        }

        $domain = trim(substr($address, $at + 1), '<> ');

        return '' === $domain ? null : $domain;
    }

    /** Lookup keys in the month table are lower case, no trailing dot. */
    private function monthKey(string $token): string
    {
        return mb_strtolower(rtrim(trim($token), '.'));
    }
}
