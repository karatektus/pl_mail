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
 * Bills: money owed, what it is owed for, and when it is due.
 *
 * Two things have to be true before anything is emitted, and neither on its
 * own is enough. The mail has to TALK like a bill — "Rechnung", "invoice",
 * "Zahlungsaufforderung", "amount due" — and it has to NAME A SUM in a
 * currency. A newsletter announcing "unsere Rechnung für den Sommer" states
 * no money; a shop confirmation full of prices is not a demand for payment.
 * Only the pair is a bill, and a card that says "you owe something" without
 * saying how much is the kind of half-fact this pipeline exists not to
 * produce.
 *
 * **Amounts are parsed in both conventions, and that is the one piece of
 * arithmetic here worth being nervous about.** `1.234,56 €` and `€1,234.56`
 * are the same sum written by two halves of the world, and reading the German
 * one with the English rule turns twelve hundred euros into one euro twenty-
 * three — a card off by three orders of magnitude, which is worse than no
 * card because it looks plausible. The rule below is positional rather than
 * locale-guessing: when both separators appear, the LAST one is the decimal
 * point; when only one appears, three digits behind it make it a thousands
 * separator and one or two make it a decimal.
 *
 * The due date is read the way ParcelExtractor reads an ETA — only inside a
 * window around a word that promises one — and is null otherwise, because the
 * date in a footer is a date, not a deadline. The date helpers and the month
 * table are LIFTED from that class rather than shared: hoisting them into a
 * common helper touches an extractor this change has no business rewriting,
 * and the duplication is visible enough to be collapsed deliberately later.
 */
final readonly class InvoiceExtractor implements InsightExtractorInterface
{
    /**
     * The cheap gate: subject words that make a mail plausibly a bill,
     * lowercase and matched as substrings so "Rechnungskorrektur" and
     * "Ihre Monatsrechnung" both open it. The wider vocabulary — "Betrag",
     * "total", "fällig" — is deliberately NOT here: those are body words, and
     * a subject is all supports() is allowed to read.
     *
     * @var list<string>
     */
    private const array SUBJECT_WORDS = [
        'rechnung', 'invoice', 'zahlungsaufforderung', 'zahlungserinnerung',
        'mahnung', 'amount due', 'payment due', 'bill', 'faktura', 'quittung',
    ];

    /**
     * What extract() insists the whole mail says before it will read a sum as
     * money owed. Wider than the subject gate because the body is where a
     * bill actually states its terms, and re-checked here rather than trusted
     * from supports() so this method stands on its own.
     *
     * @var list<string>
     */
    private const array CONTEXT_WORDS = [
        'rechnung', 'invoice', 'zahlungsaufforderung', 'zahlungserinnerung',
        'mahnung', 'amount due', 'payment due', 'betrag', 'gesamtbetrag',
        'total', 'fällig', 'faellig', 'due date', 'zu zahlen', 'zahlbar',
    ];

    /**
     * Words that introduce the sum that matters, as opposed to a line item.
     * A bill lists parts and then states its total; the amount standing next
     * to one of these is the one the card owes.
     */
    private const string TOTAL_WORDS = '~gesamtbetrag|gesamtsumme|rechnungsbetrag|zu zahlender betrag|zu zahlen|amount due|total due|total amount|grand total|\btotal\b|\bbetrag\b~iu';

    /** Words that promise a deadline; a date elsewhere is not one. */
    private const string DUE_WORDS = '~fällig am|fällig bis|faellig am|faellig bis|fälligkeit|faelligkeit|zahlbar bis|zahlbar am|zahlung bis|bitte bis|due date|due on|due by|payable by|pay by~iu';

    /**
     * The amount shapes, in both conventions and with the currency on either
     * side. A currency is REQUIRED — one of the two optional captures has to
     * have fired — which is what keeps "12.000 Kunden" and "Version 2.5" out
     * of a field that means money.
     */
    private const string AMOUNT = '~(?:(€|\$|£|EUR|USD|GBP|CHF)\s*)?(\d{1,3}(?:[.,\x{202F}\x{00A0} ]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)\s*(€|\$|£|EUR|USD|GBP|CHF)?~iu';

    /** How far past a total word its amount may sit. One label and a colon. */
    private const int TOTAL_WINDOW = 40;

    /** How far past a due-date word its date may sit — ParcelExtractor's ETA_WINDOW, same reasoning. */
    private const int DUE_WINDOW = 80;

    /**
     * How the title prints each currency: the symbol, and which side of the
     * number it belongs on. Dollar and pound go in front wherever they are
     * written; euro and franc follow the number in the convention this
     * mailbox is mostly in. Getting the side wrong does not change the
     * meaning, but "$ 99.00" is instantly recognizable as machine output.
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const array CURRENCY_SYMBOLS = [
        'EUR' => ['€', false],
        'USD' => ['$', true],
        'GBP' => ['£', true],
        'CHF' => ['CHF', true],
    ];

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
        return 'invoice';
    }

    public function icon(): string
    {
        return 'fa-solid fa-file-invoice';
    }

    public function priority(): int
    {
        return 90;
    }

    public function supports(Message $message): bool
    {
        $subject = mb_strtolower(trim((string) $message->subject));

        foreach (self::SUBJECT_WORDS as $word) {
            if (true === str_contains($subject, $word)) {
                return true;
            }
        }

        return false;
    }

    public function extract(Message $message): array
    {
        $subject = trim((string) $message->subject);
        $body = trim((string) $message->bodyText);
        $whole = $subject . "\n" . $body;

        if (false === $this->mentionsABill($whole)) {
            return [];
        }

        $amount = $this->amountIn($whole);

        if (null === $amount) {
            return [];
        }

        $issuer = $this->issuer($message);
        $number = $this->invoiceNumberIn($whole);
        $due = $this->dueDate($whole, $message->receivedAt);

        return [new InsightDraft(
            kind: InsightKind::Invoice,
            title: sprintf('%s · %s', $issuer, $this->printed($amount)),
            dedupeKey: $this->dedupeKey($message, $number, $amount, $due),
            payload: [
                'amount'        => $amount['printed'],
                'currency'      => $amount['currency'],
                'invoiceNumber' => $number,
                'issuer'        => $issuer,
            ],
            happensAt: $due,
        )];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function mentionsABill(string $whole): bool
    {
        $text = mb_strtolower($whole);

        foreach (self::CONTEXT_WORDS as $word) {
            if (true === str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sum the bill is for: the amount standing next to a total word, and
     * failing that the LARGEST amount stated.
     *
     * Largest rather than first, because a bill that never says "Gesamt"
     * still lists its parts before its sum, and a sum is not smaller than its
     * parts. That rule is also what makes the decimal-separator question load-
     * bearing rather than cosmetic: read `1.234,56 €` as 1.23 and the shipping
     * line at 9,99 € wins the comparison and the card shows the postage.
     *
     * @return array{printed: string, currency: string, value: float}|null
     */
    private function amountIn(string $whole): ?array
    {
        $amounts = $this->amountsIn($whole);

        if ([] === $amounts) {
            return null;
        }

        preg_match_all(self::TOTAL_WORDS, $whole, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$word, $offset]) {
            $end = $offset + strlen($word) + self::TOTAL_WINDOW;

            foreach ($amounts as $amount) {
                if ($amount['offset'] >= $offset && $amount['offset'] <= $end) {
                    return ['printed' => $amount['printed'], 'currency' => $amount['currency'], 'value' => $amount['value']];
                }
            }
        }

        $best = $amounts[0];

        foreach ($amounts as $amount) {
            if ($amount['value'] > $best['value']) {
                $best = $amount;
            }
        }

        return ['printed' => $best['printed'], 'currency' => $best['currency'], 'value' => $best['value']];
    }

    /**
     * Every currency amount in the text, in reading order, each with what it
     * is worth and where it stood.
     *
     * @return list<array{printed: string, currency: string, value: float, offset: int}>
     */
    private function amountsIn(string $text): array
    {
        preg_match_all(self::AMOUNT, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $found = [];

        foreach ($matches as $match) {
            $currency = $this->currencyCode($match[1][0]) ?? $this->currencyCode($match[3][0] ?? '');

            // No symbol on either side means this was a plain number, and a
            // plain number in a bill is a quantity, a year or a customer id.
            if (null === $currency) {
                continue;
            }

            $printed = $match[2][0];

            $found[] = [
                'printed'  => $printed,
                'currency' => $currency,
                'value'    => $this->value($printed),
                'offset'   => $match[0][1],
            ];
        }

        return $found;
    }

    /**
     * What a printed amount is worth, whichever convention wrote it.
     *
     * Both separators present: the last one is the decimal point, everything
     * before it is grouping. One separator: three digits behind it is
     * grouping ("1.234" is twelve hundred), one or two is a decimal ("99.00"
     * and "9,99"). Neither: an integer. Nothing here consults a locale — the
     * mail is the only evidence, and it is enough.
     */
    private function value(string $printed): float
    {
        $digitsOnly = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $printed);

        $lastDot = strrpos($digitsOnly, '.');
        $lastComma = strrpos($digitsOnly, ',');

        $decimal = null;

        if (false !== $lastDot && false !== $lastComma) {
            $decimal = $lastDot > $lastComma ? '.' : ',';
        } elseif (false !== $lastDot || false !== $lastComma) {
            $separator = false !== $lastDot ? '.' : ',';
            $position = false !== $lastDot ? $lastDot : $lastComma;

            // Exactly three digits behind the only separator is the grouped
            // form; anything shorter is a fraction. "1.234" is 1234 and
            // "1.23" is one and a bit, and no amount of locale sniffing beats
            // that on the mail this parser actually gets.
            $decimal = 3 === strlen($digitsOnly) - $position - 1 ? null : $separator;
        }

        if (null === $decimal) {
            return (float) str_replace(['.', ','], '', $digitsOnly);
        }

        $at = '.' === $decimal ? $lastDot : $lastComma;
        $whole = str_replace(['.', ','], '', substr($digitsOnly, 0, (int) $at));
        $fraction = substr($digitsOnly, (int) $at + 1);

        return (float) ($whole . '.' . $fraction);
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
     * The invoice number, only where a word announces one. Letters, digits,
     * dashes and slashes, because "RE-2026/0042" is as common a shape as a
     * plain run of digits.
     */
    private function invoiceNumberIn(string $whole): ?string
    {
        $pattern = '~(?:rechnungsnummer|rechnungs-?nr\.?|rechnung\s+nr\.?|invoice\s*(?:number|no\.?|#)|belegnummer)\s*[:#]?\s*([A-Z0-9][A-Z0-9/\-]{2,29})~iu';

        if (1 !== preg_match($pattern, $whole, $match)) {
            return null;
        }

        return mb_strtoupper(rtrim($match[1], '-/'));
    }

    /**
     * The deadline the mail states, or null when it states none. Every
     * announcing word gets a chance — "Zahlbar bis" and "Fälligkeit" are
     * frequently different lines of the same bill.
     */
    private function dueDate(string $whole, ?DateTimeImmutable $receivedAt): ?DateTimeImmutable
    {
        preg_match_all(self::DUE_WORDS, $whole, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$word, $offset]) {
            $date = $this->dateIn(substr($whole, $offset, strlen($word) + self::DUE_WINDOW), $receivedAt);

            if (null !== $date) {
                return $date;
            }
        }

        return null;
    }

    /**
     * The identity of the BILL. Its number where the mail states one — that
     * is what a reminder for the same bill repeats — and otherwise the sender
     * with the sum and the deadline, which is the closest a numberless bill
     * gets to naming itself.
     *
     * @param array{printed: string, currency: string, value: float} $amount
     */
    private function dedupeKey(Message $message, ?string $number, array $amount, ?DateTimeImmutable $due): string
    {
        if (null !== $number) {
            return $number;
        }

        return sprintf(
            '%s|%s %s|%s',
            $this->senderDomain($message) ?? 'unknown',
            number_format($amount['value'], 2, '.', ''),
            $amount['currency'],
            $due?->format('Y-m-d') ?? '-',
        );
    }

    /**
     * The sum as the card wears it: the digits exactly as the mail printed
     * them, with the currency's own symbol. Re-formatting the number would
     * mean showing a German bill in English grouping, which reads as a
     * different amount to the person who received it.
     *
     * @param array{printed: string, currency: string, value: float} $amount
     */
    private function printed(array $amount): string
    {
        [$symbol, $leading] = self::CURRENCY_SYMBOLS[$amount['currency']] ?? [$amount['currency'], false];

        return true === $leading
            ? $symbol . $amount['printed']
            : $amount['printed'] . ' ' . $symbol;
    }

    /** The name the card wears: the sender's own, its domain otherwise. */
    private function issuer(Message $message): string
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
     * bill names a day, never an hour, and midnight would render as the
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

        // 24. Dezember [2026] — the month name removes the day/month
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

        // December 24[, 2026].
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
     * bill a year later must land on the day it always did.
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
