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
 * Subscriptions: the charge that is about to happen by itself.
 *
 * Two flavours, one extractor, because they are the same fact from either
 * end: a subscription that renews on the 3rd and a trial that ends on the 3rd
 * both mean "money moves on the 3rd unless you act". Splitting them into two
 * classes would duplicate every phrase table and every date rule to express a
 * difference that is one payload field — `kind`, `renewal` or `trial_end`.
 *
 * The trial reading wins when a mail states both, and that is a deliberate
 * asymmetry: "deine Testphase endet am 3. und dein Abo verlängert sich
 * anschließend" describes one moment, and the trial ending is the half the
 * user can still do something about.
 *
 * Nothing is emitted from the subject alone. "Dein Abo" opens the gate and
 * proves nothing; a mail has to state one of the phrases below in full before
 * there is a fact, because the alternative is a card on every marketing mail
 * a streaming service sends. The date and the amount are read only where the
 * mail states them and are otherwise null — see InsightExtractorInterface on
 * returning nothing rather than probably-something.
 *
 * The dedupe key is the sender and the DATE, never the wording: "your trial
 * ends in 3 days" and "your trial ends tomorrow" are two mails about one
 * moment, and two cards for one moment is exactly the noise that gets the
 * radar switched off.
 */
final readonly class SubscriptionExtractor implements InsightExtractorInterface
{
    /**
     * The cheap gate. Matched with word boundaries rather than as substrings,
     * which is not fussiness: "abo" sits inside "about" and would open this
     * gate on half the English mail in a mailbox.
     */
    private const string SUBJECT_HINTS = '~\b(?:abo|abos|abonnement|probeabo|subscription|membership|mitgliedschaft|testphase|testzeitraum|trial|renews?|renewal|renewing|verlängert|verlaengert|verlängerung|verlaengerung|plan)\b~iu';

    /**
     * A trial that is ending. Full phrases, because the single words in them
     * ("Testphase", "trial") appear in every upsell mail ever sent.
     *
     * @var list<string>
     */
    private const array TRIAL_PHRASES = [
        'testphase endet', 'testphase läuft', 'testphase laeuft',
        'testzeitraum endet', 'probeabo endet', 'probemonat endet',
        'trial ends', 'trial will end', 'trial period ends', 'trial expires',
        'free trial ends',
    ];

    /**
     * A subscription that renews itself.
     *
     * @var list<string>
     */
    private const array RENEWAL_PHRASES = [
        'verlängert sich automatisch', 'wird automatisch verlängert',
        'verlaengert sich automatisch', 'wird automatisch verlaengert',
        'automatische verlängerung', 'automatische verlaengerung',
        'verlängert sich am', 'verlaengert sich am',
        'renews on', 'renews automatically', 'will renew', 'auto-renew',
        'automatically renew', 'automatic renewal', 'subscription renews',
    ];

    /**
     * How far either side of the phrase the date may sit. Both directions,
     * because "Am 3. September verlängert sich dein Abo" and "dein Abo
     * verlängert sich am 3. September" are the same sentence written from
     * either end.
     */
    private const int DATE_WINDOW = 90;

    /**
     * The amount shapes, both conventions, currency on either side and
     * required — the same rule InvoiceExtractor states at greater length: a
     * number with no currency beside it is not money.
     */
    private const string AMOUNT = '~(?:(€|\$|£|EUR|USD|GBP|CHF)\s*)?(\d{1,3}(?:[.,\x{202F}\x{00A0} ]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)\s*(€|\$|£|EUR|USD|GBP|CHF)?~iu';

    /**
     * Month names, spelled and abbreviated, in both languages — written out
     * rather than derived from ICU for the same reason ParcelExtractor writes
     * them out: the parse must give the same answer on every machine.
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
        return 'subscription';
    }

    public function icon(): string
    {
        return 'fa-solid fa-arrows-rotate';
    }

    public function priority(): int
    {
        return 70;
    }

    public function supports(Message $message): bool
    {
        return 1 === preg_match(self::SUBJECT_HINTS, (string) $message->subject);
    }

    public function extract(Message $message): array
    {
        $subject = trim((string) $message->subject);
        $body = trim((string) $message->bodyText);
        $whole = $subject . "\n" . $body;

        // Folded once, and the offsets below index THIS string: case folding
        // can move byte offsets, so the window must be cut from the same text
        // the phrase was found in. Nothing is lost — dates are digits and
        // month names, and those are matched case-insensitively anyway.
        $lower = mb_strtolower($whole);

        $found = $this->flavourIn($lower);

        if (null === $found) {
            return [];
        }

        [$kind, $offset, $phrase] = $found;

        $service = $this->service($message);
        $happensAt = $this->dateAround($lower, $offset, strlen($phrase), $message->receivedAt);
        $amount = $this->amountIn($whole);

        return [new InsightDraft(
            kind: InsightKind::Subscription,
            // The service alone. What happens to it is a payload field the
            // card translates, rather than English prose baked into a title
            // that a German reader would then see untranslated.
            title: $service,
            dedupeKey: $this->dedupeKey($message, $kind, $happensAt),
            payload: [
                'service'  => $service,
                'kind'     => $kind,
                'amount'   => $amount['printed'] ?? null,
                'currency' => $amount['currency'] ?? null,
            ],
            happensAt: $happensAt,
        )];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Which flavour the mail states, where it states it, and in which words.
     * Trials are asked first — see the class doc on why the endable half
     * wins a mail that says both.
     *
     * @param string $text the mail, already lower-cased, so the offset it
     *                     returns indexes the string the caller will cut
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function flavourIn(string $text): ?array
    {
        foreach (self::TRIAL_PHRASES as $phrase) {
            $at = strpos($text, $phrase);

            if (false !== $at) {
                return ['trial_end', $at, $phrase];
            }
        }

        foreach (self::RENEWAL_PHRASES as $phrase) {
            $at = strpos($text, $phrase);

            if (false !== $at) {
                return ['renewal', $at, $phrase];
            }
        }

        return null;
    }

    /**
     * The date the phrase is about, or null when it names none. A window
     * rather than the whole text, for the reason ParcelExtractor gives about
     * its ETA: the date in a footer is a date, not this mail's deadline.
     */
    private function dateAround(string $whole, int $offset, int $length, ?DateTimeImmutable $receivedAt): ?DateTimeImmutable
    {
        $start = max(0, $offset - self::DATE_WINDOW);

        return $this->dateIn(
            substr($whole, $start, $offset - $start + $length + self::DATE_WINDOW),
            $receivedAt,
        );
    }

    /**
     * The first currency amount the mail states, or nulls when it states
     * none. First rather than largest — unlike a bill, a renewal mail names
     * one price, and the numbers around it are plan comparisons a card has no
     * business promoting to "what you will be charged".
     *
     * @return array{printed: ?string, currency: ?string}
     */
    private function amountIn(string $whole): array
    {
        preg_match_all(self::AMOUNT, $whole, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $currency = $this->currencyCode($match[1]) ?? $this->currencyCode($match[3] ?? '');

            if (null !== $currency) {
                return ['printed' => $match[2], 'currency' => $currency];
            }
        }

        return ['printed' => null, 'currency' => null];
    }

    /** The ISO code behind whichever way the mail wrote its currency. */
    private function currencyCode(string $token): ?string
    {
        return match (mb_strtoupper(trim($token))) {
            '€', 'EUR'  => 'EUR',
            '$', 'USD'  => 'USD',
            '£', 'GBP'  => 'GBP',
            'CHF'       => 'CHF',
            default     => null,
        };
    }

    /**
     * Sender plus the date: the whole point, so the three mails a service
     * sends about one renewal land on one card. Where no date could be read
     * the flavour stands in — a poorer key, but the alternative is a card per
     * reminder, and a service sends at most one renewal and one trial ending
     * at a time.
     */
    private function dedupeKey(Message $message, string $kind, ?DateTimeImmutable $happensAt): string
    {
        return sprintf(
            '%s|%s',
            $this->senderDomain($message) ?? 'unknown',
            $happensAt?->format('Y-m-d') ?? $kind,
        );
    }

    /** The name the card wears: the sender's own, its domain otherwise. */
    private function service(Message $message): string
    {
        $name = trim((string) $message->fromName);

        if ('' !== $name) {
            return $name;
        }

        return $this->senderDomain($message) ?? 'Unknown';
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

    /**
     * The first explicit date in this window, at noon UTC — noon because a
     * renewal names a day, never an hour, and midnight would render as the
     * previous evening in any timezone west of the sender.
     */
    private function dateIn(string $window, ?DateTimeImmutable $receivedAt): ?DateTimeImmutable
    {
        // 24.12.2026 — the German convention, day first, full year required.
        if (1 === preg_match('~\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b~', $window, $m)) {
            return $this->dateFrom((int) $m[1], (int) $m[2], (int) $m[3], $receivedAt);
        }

        // 2026-12-24 — the one form no locale reads two ways.
        if (1 === preg_match('~\b(\d{4})-(\d{1,2})-(\d{1,2})\b~', $window, $m)) {
            return $this->dateFrom((int) $m[3], (int) $m[2], (int) $m[1], $receivedAt);
        }

        $months = implode('|', array_keys(self::MONTHS));

        // 3. September [2027] — the month name removes the day/month
        // ambiguity, which is what lets the year be optional here and not in
        // the numeric forms.
        if (1 === preg_match(sprintf('~\b(\d{1,2})\.?\s*(%s)\b(?:\s*,?\s*(\d{4}))?~iu', $months), $window, $m)) {
            return $this->dateFrom(
                (int) $m[1],
                self::MONTHS[$this->monthKey($m[2])],
                '' === ($m[3] ?? '') ? null : (int) $m[3],
                $receivedAt,
            );
        }

        // September 3[, 2027].
        if (1 === preg_match(sprintf('~\b(%s)\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b(?:\s*,?\s*(\d{4}))?~iu', $months), $window, $m)) {
            return $this->dateFrom(
                (int) $m[2],
                self::MONTHS[$this->monthKey($m[1])],
                '' === ($m[3] ?? '') ? null : (int) $m[3],
                $receivedAt,
            );
        }

        return null;
    }

    /**
     * A calendar day as a noon-UTC instant, with a missing year resolved
     * against the MAIL, never the clock — a backfill re-reading a December
     * mail a year later must land on the day it always did.
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

    /** Lookup keys in the month table are lower case, no trailing dot. */
    private function monthKey(string $token): string
    {
        return mb_strtolower(rtrim(trim($token), '.'));
    }
}
