<?php

declare(strict_types=1);

namespace App\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Mail\Message;
use App\Service\Insight\InsightDraft;
use App\Service\Insight\InsightExtractorInterface;
use DateTimeImmutable;

/**
 * One-time codes: the six digits a login mail exists to deliver.
 *
 * The most useful card in the set, because it is the one that replaces
 * opening the mail at all — the code is read off the radar while the sign-in
 * form is still on screen. That payoff is also what makes this the easiest
 * extractor to get wrong: a bare digit run is the least distinctive thing in
 * a mailbox, and an order number promoted to a login code is a card that
 * invites the user to type a wrong secret into a form that locks them out.
 *
 * So the CONTEXT WORD IS MANDATORY. Nothing is read from a number's shape
 * alone: a candidate counts only when it sits within a few dozen characters
 * of a word that promises a code — "Bestätigungscode", "verification code",
 * "Einmalcode", "2FA" — on either side of it, because both "Your code is
 * 123456" and "123456 ist dein Bestätigungscode" are how these mails are
 * actually written. Years, money, and anything introduced as a Bestellnummer
 * or Kundennummer are refused even inside that window; see
 * InsightExtractorInterface on returning nothing rather than probably-
 * something.
 *
 * **happensAt is an EXPIRY here, not an occurrence** — the one place in this
 * family where the timestamp says when the fact stops being true rather than
 * when something happens. That is deliberate. A code is useful for minutes
 * and is a secret; a card still offering it an hour later is both useless and
 * a small leak, so the radar is given the moment to drop it by. The expiry is
 * taken only from what the mail states ("gültig für 10 Minuten", "expires in
 * 15 minutes", "gültig bis 09:30") and is otherwise null: guessing a default
 * ten minutes would either retire a code that was still good or, worse, leave
 * a dead one looking live.
 *
 * The code itself is put in the payload on purpose — reading it there is the
 * entire feature — and nowhere else; the row is the only copy, and dropping
 * the insight drops the code with it.
 */
final readonly class OtpExtractor implements InsightExtractorInterface
{
    /**
     * Subject words that make a mail plausibly about a login, lowercase and
     * matched as substrings so "Bestätigungscode", "Anmeldecode" and
     * "verification" all open the gate from whichever compound they sit in.
     * Deliberately loose — extract() demanding a context word AND a code
     * shape is what pays for the looseness.
     *
     * @var list<string>
     */
    private const array SUBJECT_HINTS = [
        'code', 'verification', 'verifizier', 'bestätigung', 'bestaetigung',
        'sicherheits', 'einmal', 'one-time', 'one time', 'otp', '2fa',
        'anmeld', 'login', 'log in', 'sign in', 'sign-in', 'authentifizier',
    ];

    /**
     * The words a code may stand next to. Everything this extractor emits is
     * anchored on one of these; there is no shape distinctive enough to skip
     * the chaperone, which is the difference between this and the S10 numbers
     * ParcelExtractor lets vouch for themselves.
     */
    private const string CONTEXT = '~verification code|security code|one[- ]time (?:code|password|passcode)|access code|login code|sign[- ]in code|bestätigungscode|bestaetigungscode|sicherheitscode|einmalcode|einmal-code|verifizierungscode|anmeldecode|zugangscode|\bOTP\b|\b2FA\b~iu';

    /**
     * How far in front of the context word a code may sit. Short, because
     * "123456 ist dein Bestätigungscode" puts it immediately in front and
     * nothing legitimate puts it further.
     */
    private const int LOOK_BEHIND = 40;

    /**
     * How far past the context word a code may sit. Longer than LOOK_BEHIND:
     * "Your verification code for plMail is: 123456" is all preamble, and the
     * code often lands on the next line.
     */
    private const int LOOK_AHEAD = 80;

    /**
     * What the run of digits in front of a candidate may be introduced as
     * without being a code. Lowercase, checked against the text immediately
     * preceding the candidate — the labels that produce exactly this shape
     * and are emphatically not secrets.
     *
     * @var list<string>
     */
    private const array NOT_A_CODE = [
        'bestellnummer', 'kundennummer', 'auftragsnummer', 'rechnungsnummer',
        'vertragsnummer', 'sendungsnummer', 'order number', 'order no',
        'customer number', 'invoice number', 'reference number', 'ticket number',
    ];

    /**
     * How much text before a candidate is inspected for one of those labels.
     * Wide enough for "Ihre Bestellnummer lautet 123456", narrow enough that
     * the previous sentence's subject cannot disqualify this one's number.
     */
    private const int LABEL_WINDOW = 32;

    /**
     * A four-digit run in this range is a year, and years are everywhere in
     * mail — copyright footers, "seit 1998", the date itself. No login code
     * is worth the false positives of admitting them.
     */
    private const int YEAR_FLOOR = 1900;

    private const int YEAR_CEILING = 2100;

    /**
     * How far either side of a validity word a stated lifetime may sit.
     * "Dieser Code ist 10 Minuten gültig" is the long form and fits.
     */
    private const int VALIDITY_WINDOW = 60;

    public static function key(): string
    {
        return 'otp';
    }

    public function icon(): string
    {
        return 'fa-solid fa-key';
    }

    /**
     * Above every other extractor: a code is worth minutes and the others are
     * worth days, so when two of them claim one mail — a shop's "here is your
     * login code" also carrying an order number — the perishable fact wins.
     */
    public function priority(): int
    {
        return 130;
    }

    public function supports(Message $message): bool
    {
        $subject = mb_strtolower(trim((string) $message->subject));

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

        // Subject and body are read as one text: the code sits in the subject
        // and the context word in the body about as often as the reverse.
        $whole = $subject . "\n" . $body;

        $code = $this->codeIn($whole);

        if (null === $code) {
            return [];
        }

        $issuer = $this->issuer($message);
        $minutes = $this->expiresInMinutes($whole);

        return [new InsightDraft(
            kind: InsightKind::Otp,
            title: $issuer . ' · ' . $code,
            // Issuer plus code: a re-sent code is a NEW fact and deserves its
            // own card — the old one is dead — while the same code quoted in
            // a follow-up ("we sent you 123456") is the same secret and must
            // land on the card that already holds it.
            dedupeKey: mb_strtolower($this->senderDomain($message) ?? 'unknown') . '|' . $code,
            payload: [
                'code'             => $code,
                'issuer'           => $issuer,
                'expiresInMinutes' => $minutes,
            ],
            happensAt: $this->expiresAt($whole, $minutes, $message->receivedAt),
        )];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The code this mail delivers, or null when it delivers none.
     *
     * Every context word gets a chance, in reading order, and the first
     * candidate that survives the refusals wins. There is no "best" candidate
     * to weigh here: a mail states one code, and a mail whose first anchored
     * candidate is the wrong one is a mail this extractor should not be
     * guessing about anyway.
     */
    private function codeIn(string $text): ?string
    {
        preg_match_all(self::CONTEXT, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$word, $offset]) {
            $start = max(0, $offset - self::LOOK_BEHIND);
            $length = $offset - $start + strlen($word) + self::LOOK_AHEAD;

            $code = $this->candidateIn(substr($text, $start, $length), $text, $start);

            if (null !== $code) {
                return $code;
            }
        }

        return null;
    }

    /**
     * The first acceptable code shape in this window, in reading order.
     *
     * Two shapes, split so neither has to cover for the other: a run of 4–8
     * digits, and a 6–8 character upper-case token that mixes letters AND
     * digits. The mix is required because "PLEASE" and "GITHUB" are the same
     * shape as a code otherwise, and pure digit runs are already the first
     * shape's business.
     *
     * $offsetInText is where the window starts in the whole text, so the
     * refusals below can look at what precedes a candidate even when it sits
     * on the window's own edge.
     */
    private function candidateIn(string $window, string $text, int $offsetInText): ?string
    {
        $candidates = [];

        // Neither neighbour may be a digit or a separator between digits:
        // that is what keeps 1.234,56 and 09:30 out, and it does so by shape
        // rather than by a list of things money looks like.
        foreach ($this->matchesWithOffsets('~(?<![\dA-Za-z.,])\d{4,8}(?![.,]?\d)(?![A-Za-z])~', $window) as $candidate) {
            $candidates[] = $candidate;
        }

        foreach ($this->matchesWithOffsets('~(?<![\dA-Za-z])[A-Z0-9]{6,8}(?![\dA-Za-z])~', $window) as $candidate) {
            if (1 === preg_match('~\d~', $candidate[0]) && 1 === preg_match('~[A-Z]~', $candidate[0])) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        foreach ($candidates as [$candidate, $offset]) {
            if (true === $this->isRefused($candidate, $text, $offsetInText + $offset)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * The three ways a well-anchored candidate is still not a code: it is a
     * year, it carries a currency on either side, or the words right in front
     * of it introduced it as some other kind of number.
     */
    private function isRefused(string $candidate, string $text, int $offset): bool
    {
        if (4 === strlen($candidate)
            && self::YEAR_FLOOR <= (int) $candidate
            && self::YEAR_CEILING >= (int) $candidate
        ) {
            return true;
        }

        $before = substr($text, max(0, $offset - self::LABEL_WINDOW), min($offset, self::LABEL_WINDOW));
        $after = substr($text, $offset + strlen($candidate), 8);

        if (1 === preg_match('~(?:€|\$|£|EUR|USD|GBP|CHF)\s*$~iu', $before)
            || 1 === preg_match('~^\s*(?:€|\$|£|(?:EUR|USD|GBP|CHF)\b)~iu', $after)
        ) {
            return true;
        }

        $before = mb_strtolower($before);

        foreach (self::NOT_A_CODE as $label) {
            if (true === str_contains($before, $label)) {
                return true;
            }
        }

        return false;
    }

    /** The lifetime the mail states, in minutes, or null when it states none. */
    private function expiresInMinutes(string $text): ?int
    {
        // Anchored like the code itself, and for the same reason: a bare "in
        // 10 Minuten" is as likely to be about a delivery window or a support
        // queue as about this code, so a validity word has to be standing
        // beside it. The window reaches both ways — English writes "expires
        // in 15 minutes", German just as happily "10 Minuten gültig".
        preg_match_all(
            '~gültig|gueltig|valid|expires?|expiry|läuft ab|laeuft ab|verfällt|verfaellt~iu',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[0] as [$word, $offset]) {
            $start = max(0, $offset - self::VALIDITY_WINDOW);
            $window = substr($text, $start, $offset - $start + strlen($word) + self::VALIDITY_WINDOW);

            if (1 === preg_match('~\b(\d{1,3})\s*(?:minuten|minute|mins?|min\.)~iu', $window, $match)) {
                return (int) $match[1];
            }

            if (1 === preg_match('~\b(\d{1,2})\s*(?:stunden|stunde|hours?|hrs?)~iu', $window, $match)) {
                // Hours are folded into minutes rather than kept as their own
                // unit: the card shows one countdown, and the renderer never
                // has to decide which unit it is looking at.
                return 60 * (int) $match[1];
            }
        }

        return null;
    }

    /**
     * When the code dies: receivedAt plus the stated lifetime, or the wall
     * clock the mail names, and null when it names neither.
     *
     * The stated lifetime is preferred over a stated clock time because it is
     * unambiguous — "gültig bis 09:30" carries no zone, and reading it in the
     * mail's own zone is the closest thing to what the sender meant. A time
     * already behind the mail is tomorrow's: a code sent at 23:55 and good
     * until 00:10 is a normal five past midnight, not a code that expired a
     * day before it was issued.
     */
    private function expiresAt(string $text, ?int $minutes, ?DateTimeImmutable $receivedAt): ?DateTimeImmutable
    {
        if (null === $receivedAt) {
            return null;
        }

        if (null !== $minutes) {
            return $receivedAt->modify(sprintf('+%d minutes', $minutes));
        }

        if (1 !== preg_match('~(?:gültig bis|gueltig bis|valid until|expires at|expires on|bis)\s*(\d{1,2}):(\d{2})~iu', $text, $match)) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];

        if (24 <= $hour || 60 <= $minute) {
            return null;
        }

        $expiry = $receivedAt->setTime($hour, $minute);

        return $expiry < $receivedAt ? $expiry->modify('+1 day') : $expiry;
    }

    /**
     * Who the code is for, as the card names it: the sender's own name where
     * it has one, its domain otherwise. A sign-in card that cannot say which
     * service it belongs to is worth very little, but it is still worth the
     * code.
     */
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
     * @return list<array{0: string, 1: int}> every match of the whole
     *                                        pattern with its byte offset
     */
    private function matchesWithOffsets(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

        /** @var list<array{0: string, 1: int}> $found */
        $found = $matches[0];

        return $found;
    }
}
