<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Entity\Account;

/**
 * Decides which account a Gmail API message payload belongs to.
 *
 * Gmail-specific address normalisation rules applied before any comparison:
 *   1. Lowercase the entire address.
 *   2. Strip the +tag suffix from the local part  (foo+bar@gmail.com → foo).
 *   3. Remove all dots from the local part        (f.o.o@gmail.com  → foo).
 *
 * After normalisation karatektus@gmail.com, kara.te.ktus@gmail.com and
 * karatektus+newsletter@gmail.com all reduce to the same canonical form.
 *
 * Entry points:
 *
 *   resolveReceivedAccount() — attribution for received mail on a Gmailify
 *                              carrier (carrier vs sibling accounts).
 *   isAddressedToAccount()   — received mail without Gmailify attribution.
 *   isSentByAccount()        — sent mail (SENT label): checks From.
 */
final class GmailAddressFilter
{
    /**
     * Attribution for received mail on a Gmailify carrier.
     *
     * Gmail stamps its OWN address into Delivered-To when it ingests
     * fetched mail, so "Delivered-To: <carrier>" is NOT evidence the mail
     * was for the carrier — for those messages To/Cc is the stronger
     * signal. Resolution order:
     *
     *   1. A sibling appears in Delivered-To  → that sibling.
     *      (the original server's stamp survived — authoritative)
     *   2. The carrier appears in To/Cc       → carrier.
     *      (genuinely addressed to the Gmail address)
     *   3. A sibling appears in To/Cc         → that sibling.
     *      (fetched mail wearing only Gmail's own Delivered-To stamp)
     *   4. The carrier appears in Delivered-To → carrier.
     *      (BCC/list mail to the Gmail address — no other signal left)
     *   5. Nothing matches                    → null.
     *
     * Known limitation: BCC/list mail delivered to a sibling and fetched
     * by Gmail carries no sibling signal at all and lands on the carrier
     * via step 4 — imported and visible, just not re-attributed.
     *
     * @param array<string,string>  $headers                     lower-cased header name → value
     * @param array<string,Account> $siblingsByNormalisedEmail   normalisedEmail → Account
     */
    public function resolveReceivedAccount(
        array   $headers,
        Account $carrier,
        array   $siblingsByNormalisedEmail,
    ): ?Account {
        $carrierNorm = $this->normalise((string) $carrier->getEmail());

        $deliveredTo = $this->normalisedList($headers['delivered-to'] ?? '');
        $toCc        = array_merge(
            $this->normalisedList($headers['to'] ?? ''),
            $this->normalisedList($headers['cc'] ?? ''),
        );

        foreach ($deliveredTo as $norm) {
            if (true === isset($siblingsByNormalisedEmail[$norm])) {
                return $siblingsByNormalisedEmail[$norm];
            }
        }

        if (true === in_array($carrierNorm, $toCc, true)) {
            return $carrier;
        }

        foreach ($toCc as $norm) {
            if (true === isset($siblingsByNormalisedEmail[$norm])) {
                return $siblingsByNormalisedEmail[$norm];
            }
        }

        if (true === in_array($carrierNorm, $deliveredTo, true)) {
            return $carrier;
        }

        return null;
    }

    /**
     * Returns true when at least one Delivered-To (or, as a fallback, To)
     * address in the headers normalises to the same canonical form as
     * $account. Used for received mail when Gmailify attribution is off —
     * only the carrier's own mail is considered then.
     *
     * @param array<string,string> $headers  lower-cased header name → value
     */
    public function isAddressedToAccount(array $headers, Account $account): bool
    {
        $accountNorm = $this->normalise((string) $account->getEmail());

        $deliveredTo = $headers['delivered-to'] ?? '';

        if ('' !== $deliveredTo) {
            foreach ($this->splitAddressList($deliveredTo) as $addr) {
                if ($this->normalise($addr) === $accountNorm) {
                    return true;
                }
            }

            return false;
        }

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
     * same canonical form as $account.
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
     * Split a raw address-list header and normalise every entry.
     *
     * @return list<string>
     */
    private function normalisedList(string $raw): array
    {
        $normalised = [];

        foreach ($this->splitAddressList($raw) as $addr) {
            $normalised[] = $this->normalise($addr);
        }

        return $normalised;
    }

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
