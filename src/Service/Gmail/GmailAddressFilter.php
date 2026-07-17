<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Entity\Account;

/**
 * Decides whether a Gmail API message payload belongs to a given account.
 *
 * Gmail-specific address normalisation rules applied before any comparison:
 *   1. Lowercase the entire address.
 *   2. Strip the +tag suffix from the local part  (foo+bar@gmail.com → foo).
 *   3. Remove all dots from the local part        (f.o.o@gmail.com  → foo).
 *
 * After normalisation karatektus@gmail.com, kara.te.ktus@gmail.com and
 * karatektus+newsletter@gmail.com all reduce to the same canonical form.
 *
 * Two entry points are provided:
 *
 *   isAddressedToAccount()  — for received mail: checks Delivered-To then To.
 *   isSentByAccount()       — for sent mail (SENT label): checks From.
 */
final class GmailAddressFilter
{
    /**
     * Returns true when at least one Delivered-To (or, as a fallback, To)
     * address in the headers normalises to the same local part as $account.
     *
     * @param array<string,string> $headers  lower-cased header name → value
     */
    public function isAddressedToAccount(array $headers, Account $account): bool
    {
        $accountNorm = $this->normalise((string) $account->getEmail());

        // Delivered-To is the most reliable header for Gmailify detection.
        // Gmail may emit several Delivered-To lines; the API folds them into
        // one comma-separated value.
        $deliveredTo = $headers['delivered-to'] ?? '';

        if ('' !== $deliveredTo) {
            foreach ($this->splitAddressList($deliveredTo) as $addr) {
                if ($this->normalise($addr) === $accountNorm) {
                    return true;
                }
            }

            // A Delivered-To header was present but none matched — this message
            // was delivered to a different address (e.g. a Gmailify alias).
            return false;
        }

        // No Delivered-To — fall back to To header.
        $to = $headers['to'] ?? '';

        foreach ($this->splitAddressList($to) as $addr) {
            if ($this->normalise($addr) === $accountNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when the From address in the headers normalises to the
     * same local part as $account.
     *
     * @param array<string,string> $headers  lower-cased header name → value
     */
    public function isSentByAccount(array $headers, Account $account): bool
    {
        $from = $headers['from'] ?? '';

        if ('' === $from) {
            return false;
        }

        $accountNorm = $this->normalise((string) $account->getEmail());

        foreach ($this->splitAddressList($from) as $addr) {
            if ($this->normalise($addr) === $accountNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply Gmail normalisation to a raw email address or "Name <email>" string.
     * Returns the normalised local-part@domain string.
     */
    public function normalise(string $raw): string
    {
        $raw = trim($raw);

        // Extract bare address from "Name <email>" format.
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            $raw = $m[1];
        }

        $raw = strtolower($raw);

        $atPos = strrpos($raw, '@');

        if (false === $atPos) {
            return $raw;
        }

        $local  = substr($raw, 0, $atPos);
        $domain = substr($raw, $atPos + 1);

        // Strip +tag
        $plusPos = strpos($local, '+');
        if (false !== $plusPos) {
            $local = substr($local, 0, $plusPos);
        }

        // Remove all dots from local part (Gmail ignores them)
        $local = str_replace('.', '', $local);

        return $local . '@' . $domain;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Split a comma-separated address list into individual raw address strings.
     *
     * @return list<string>
     */
    private function splitAddressList(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }

        // Split on commas that are not inside angle brackets.
        $parts = preg_split('/,(?![^<]*>)/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }
}
