<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\DomainHelper;
use App\Entity\Mail\Message;

/**
 * Does the display name agree with the address it is attached to?
 *
 * THE POINT OF THIS FILE IS THAT IT IS SMALL AND YOU CAN READ ALL OF IT.
 *
 * A phishing warning is a claim made to a person about a message, and a person
 * who is told "suspicious" without being told why learns to click through it.
 * Worse, a warning that fires on ordinary mail trains exactly the reflex it was
 * built to interrupt. So there is no scoring, no model, no corpus and no
 * threshold anybody can tune: there are two rules, both of which can be stated
 * in a sentence, and neither of which fires on a message whose display name is
 * a person's name.
 *
 * ── RULE 1 · DomainInName ──────────────────────────────────────────────────
 * The display name contains something shaped like a hostname — one or more
 * labels, a dot, and a 2+ letter TLD — whose REGISTRABLE domain differs from
 * the registrable domain of the From address.
 *
 *   "service@paypal.com"  <billing@sendgrid-bounce.example>   → fires
 *   "Amazon.de"           <ship@amazon.de>                    → silent
 *   "Mr. Smith"           <smith@example.com>                 → silent
 *                          (no contiguous label.tld in "Mr. Smith")
 *
 * ── RULE 2 · BrandInName ───────────────────────────────────────────────────
 * Runs ONLY when the display name identifies an organisation, which is decided
 * by one thing: it carries a legal form as a whole word (GmbH, Inc, Ltd, AG,
 * B.V., …). A display name without one is treated as a person's and never
 * judged, because "Deutsche Bahn" against bahn.de and "Jane Cooper" against
 * gmail.com are the same shape and only one of them is worth a word.
 *
 * Then: take the display name's words of 4+ letters, drop the legal form and
 * the generic corporate vocabulary (Online, Group, Services, Deutschland, …),
 * and check whether ANY survivor occurs in the sender's registrable domain
 * with punctuation removed. If at least one word survives the filter and NONE
 * of them occurs, the names disagree.
 *
 *   "Hetzner Online GmbH" <support@ownkhalsick.com>  → fires
 *      words {hetzner, online} → {hetzner} → "ownkhalsickcom" has no "hetzner"
 *   "Hetzner Online GmbH" <noreply@hetzner.com>      → silent  ("hetzner" ⊂)
 *   "Deutsche Bahn AG"    <noreply@bahn.de>          → silent  ("bahn" ⊂)
 *   "Jane Cooper"         <jane@gmail.com>           → silent  (no legal form)
 *
 * ── WHAT IT DELIBERATELY MISSES ────────────────────────────────────────────
 * Substring matching is generous on purpose, and a lookalike domain defeats
 * Rule 2 by construction: "PayPal Inc." <service@paypal-secure.example> stays
 * silent, because "paypal" does occur in "paypalsecureexample". That is the
 * chosen direction to be wrong in. Catching it would mean scoring edit
 * distances against a brand list, which is the ML theater this avoids, and
 * every false positive it bought would be spent on ordinary mail.
 *
 * ── THE SUPPRESSION ────────────────────────────────────────────────────────
 * If the message carries an Authentication-Results header in which DKIM passed
 * for the sender's own registrable domain, neither rule runs. A domain that
 * cryptographically signed the message is entitled to put what it likes in the
 * display name, and this is the one signal in a mail header that cannot be
 * forged by the sender.
 */
final readonly class SenderIdentityChecker
{
    /**
     * Legal forms, matched as whole words, case-insensitively. Presence of one
     * is the ONLY thing that makes Rule 2 run.
     *
     * @var list<string>
     */
    private const array LEGAL_FORMS = [
        'gmbh', 'mbh', 'ag', 'kg', 'ohg', 'gbr', 'ug', 'ev', 'e.v.',
        'inc', 'inc.', 'llc', 'llp', 'ltd', 'ltd.', 'limited', 'plc',
        'corp', 'corp.', 'corporation', 'company', 'co.',
        's.a.', 'sa', 'sas', 'sarl', 'srl', 's.r.l.', 's.p.a.', 'spa',
        'b.v.', 'bv', 'n.v.', 'nv', 'a/s', 'aps', 'ab', 'oy', 'oyj', 'as',
        'pty', 'pte', 'kft', 'zrt', 'sp.', 'z.o.o.', 'doo',
    ];

    /**
     * Words that name no brand. A display name made only of these has nothing
     * to check, and Rule 2 stays silent rather than guessing.
     *
     * @var list<string>
     */
    private const array GENERIC_WORDS = [
        'online', 'group', 'holding', 'holdings', 'team', 'mail', 'email',
        'service', 'services', 'support', 'customer', 'customers', 'kunde',
        'kunden', 'kundenservice', 'international', 'global', 'digital',
        'media', 'solutions', 'systems', 'system', 'technologies', 'technology',
        'company', 'companies', 'partner', 'partners', 'shop', 'store',
        'deutschland', 'germany', 'europe', 'europa', 'america', 'international',
        'info', 'news', 'newsletter', 'noreply', 'notification', 'notifications',
        'account', 'billing', 'invoice', 'rechnung', 'sales', 'office',
        'and', 'the', 'und', 'der', 'die', 'das', 'für', 'fuer', 'von',
    ];

    private const int MIN_WORD_LENGTH = 4;

    public function check(Message $message): ?SenderMismatch
    {
        $displayName = trim((string) $message->fromName);
        $actual      = DomainHelper::registrableOfAddress($message->fromAddress);

        if ('' === $displayName || null === $actual) {
            return null;
        }

        // A display name that IS the address says nothing beyond the address.
        if (0 === strcasecmp($displayName, (string) $message->fromAddress)) {
            return null;
        }

        if (true === $this->isDkimAligned($message, $actual)) {
            return null;
        }

        return $this->checkDomainInName($displayName, $actual)
            ?? $this->checkBrandInName($displayName, $actual);
    }

    /** Rule 1. */
    private function checkDomainInName(string $displayName, string $actual): ?SenderMismatch
    {
        $found = preg_match_all(
            '/(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}/i',
            $displayName,
            $matches,
        );

        if (0 === $found || false === $found) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $claimed = DomainHelper::registrable($candidate);

            if (null === $claimed || $claimed === $actual) {
                continue;
            }

            return new SenderMismatch(SenderMismatchKind::DomainInName, $claimed, $actual);
        }

        return null;
    }

    /** Rule 2. */
    private function checkBrandInName(string $displayName, string $actual): ?SenderMismatch
    {
        // Words are kept in the sender's own casing and compared in lower —
        // the comparison wants "hetzner", the warning wants to quote "Hetzner"
        // back to the reader exactly as the message spelled it, because the
        // reader is being asked to check it against what they remember seeing.
        $words = $this->words($displayName);

        if (false === $this->namesAnOrganisation($words)) {
            return null;
        }

        $brandWords = array_values(array_filter(
            $words,
            static function (string $word): bool {
                $lower = mb_strtolower($word);

                return mb_strlen($lower) >= self::MIN_WORD_LENGTH
                    && false === in_array($lower, self::GENERIC_WORDS, true)
                    && false === in_array($lower, self::LEGAL_FORMS, true)
                    && 1 === preg_match('/^\p{L}+$/u', $lower);
            },
        ));

        if (0 === count($brandWords)) {
            return null;
        }

        // Punctuation out, so "hetzner" is judged against "hetznercom" rather
        // than tripping over the dot, and a hyphenated domain cannot hide a
        // word by splitting it.
        $haystack = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($actual));

        foreach ($brandWords as $word) {
            if (true === str_contains($haystack, mb_strtolower($word))) {
                return null;
            }
        }

        return new SenderMismatch(SenderMismatchKind::BrandInName, $brandWords[0], $actual);
    }

    /**
     * @param list<string> $words
     */
    private function namesAnOrganisation(array $words): bool
    {
        foreach ($words as $word) {
            if (true === in_array(mb_strtolower($word), self::LEGAL_FORMS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function words(string $displayName): array
    {
        // Split on whitespace and commas only — a trailing dot belongs to the
        // legal form ("Inc.", "B.V.") and stripping it here would lose the one
        // signal Rule 2 depends on.
        /** @var list<string>|false $parts */
        $parts = preg_split('/[\s,]+/u', trim($displayName), -1, PREG_SPLIT_NO_EMPTY);

        return false === $parts ? [] : $parts;
    }

    /**
     * DKIM passing for the sender's own registrable domain.
     *
     * Read out of Authentication-Results, which is written by the receiving
     * side — our own provider — and is the reason it can be believed at all.
     * A header the sender could have written would be worth nothing here.
     */
    private function isDkimAligned(Message $message, string $actual): bool
    {
        $headers = $message->headers ?? [];
        $raw     = '';

        foreach ($headers as $name => $value) {
            if (0 === strcasecmp((string) $name, 'Authentication-Results')) {
                $raw = true === is_array($value) ? implode(' ', array_map(strval(...), $value)) : (string) $value;

                break;
            }
        }

        if ('' === $raw) {
            return false;
        }

        if (1 !== preg_match('/\bdkim\s*=\s*pass\b/i', $raw)) {
            return false;
        }

        if (1 !== preg_match('/header\.d\s*=\s*([a-z0-9.\-]+)/i', $raw, $match)) {
            return false;
        }

        return DomainHelper::registrable($match[1]) === $actual;
    }
}
