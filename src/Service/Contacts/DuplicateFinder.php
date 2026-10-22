<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Domain\Helper\AddressHelper;
use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;

/**
 * Finds suspected duplicate contacts within a user's address book.
 *
 * Confidence tiers (first match wins, highest confidence):
 *   100  same normalized email
 *    95  same normalized phone (digits)
 *    85  same displayName (case-insensitive) + same non-empty company
 * 70-84  nickname/middle-name variant or initial match + same company|city
 * 60-69  fuzzy name (levenshtein <= 2) + same company|city
 *
 * Shared inboxes (info@, support@, ...) and Jr./Sr. suffixes force
 * autoMergeable=false with a caution reason.
 *
 * NOTE: loads the user's contacts with one query, then compares pairs in
 * memory. Child collections (emails/phones/addresses) lazy-load per contact
 * (N+1) — acceptable for v1 address-book sizes.
 */
final class DuplicateFinder
{
    private const int MAX_PAIRS = 200;

    private const array SHARED_LOCAL_PARTS = [
        'info', 'support', 'noreply', 'no-reply', 'admin', 'sales',
    ];

    /**
     * nickname => canonical name (used bidirectionally).
     */
    private const array NICKNAMES = [
        'bob' => 'robert', 'rob' => 'robert', 'bobby' => 'robert',
        'bill' => 'william', 'billy' => 'william', 'will' => 'william', 'liam' => 'william',
        'jim' => 'james', 'jimmy' => 'james', 'jamie' => 'james',
        'dick' => 'richard', 'rick' => 'richard', 'rich' => 'richard',
        'peggy' => 'margaret', 'maggie' => 'margaret',
        'mike' => 'michael', 'steve' => 'steven', 'tom' => 'thomas',
        'dave' => 'david', 'dan' => 'daniel', 'chris' => 'christopher',
        'nick' => 'nicholas', 'tony' => 'anthony', 'sam' => 'samuel',
        'ben' => 'benjamin', 'alex' => 'alexander', 'kate' => 'katherine', 'katie' => 'katherine',
        'liz' => 'elizabeth', 'betty' => 'elizabeth', 'beth' => 'elizabeth',
        'jenny' => 'jennifer', 'jen' => 'jennifer',
        'tim' => 'timothy', 'joe' => 'joseph', 'joey' => 'joseph',
        'ed' => 'edward', 'eddie' => 'edward', 'ted' => 'edward',
        'charlie' => 'charles', 'chuck' => 'charles',
        'fred' => 'frederick', 'greg' => 'gregory', 'jeff' => 'jeffrey',
        'ken' => 'kenneth', 'larry' => 'lawrence', 'matt' => 'matthew',
        'pete' => 'peter', 'ron' => 'ronald', 'steph' => 'stephanie',
        'sue' => 'susan', 'becky' => 'rebecca', 'cathy' => 'catherine',
        'deb' => 'deborah', 'cindy' => 'cynthia', 'pam' => 'pamela',
        'kim' => 'kimberly', 'andy' => 'andrew', 'drew' => 'andrew',
        'josh' => 'joshua', 'nathan' => 'nathaniel', 'nate' => 'nathaniel',
        'pat' => 'patrick', 'phil' => 'philip', 'ray' => 'raymond',
        'stephanie' => 'stephanie',
    ];

    public function __construct(
        private ContactRepository $contacts,
    ) {
    }

    /**
     * @param int[]|null $batchIds  when given, only pairs with at least one
     *                              side in the batch are returned
     * @return list<DuplicatePair>
     */
    public function findCandidates(User $user, ?array $batchIds = null): array
    {
        // A read-only book's contacts cannot be merged (ContactMerger refuses
        // them), so a pair holding one would be a suggestion with no way to act
        // on it. Leaving them out also keeps a company directory of thousands
        // out of the pairwise comparison below.
        $all = array_values(array_filter(
            $this->contacts->findBy(['usr' => $user, 'trashedAt' => null]),
            static fn (Contact $contact): bool => $contact->isEditable(),
        ));
        $batch = null === $batchIds ? null : array_flip($batchIds);

        $pairs = [];
        $count = count($all);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $a = $all[$i];
                $b = $all[$j];
                if (null !== $batch && !isset($batch[$a->id]) && !isset($batch[$b->id])) {
                    continue;
                }
                $pair = $this->compare($a, $b);
                if (null !== $pair) {
                    $pairs[] = $pair;
                }
            }
        }

        usort($pairs, static fn (DuplicatePair $x, DuplicatePair $y) => $y->confidence <=> $x->confidence);

        return array_slice($pairs, 0, self::MAX_PAIRS);
    }

    private function compare(Contact $a, Contact $b): ?DuplicatePair
    {
        $nameA = $this->norm($a->displayName);
        $nameB = $this->norm($b->displayName);

        // Tier 1: same normalized email -> 100
        $sharedEmails = array_intersect($this->emails($a), $this->emails($b));
        if ([] !== $sharedEmails) {
            return $this->finalize($a, $b, 100, ['same email address'], true);
        }

        // Tier 2: same normalized phone (digits) -> 95
        $sharedPhones = array_intersect($this->phones($a), $this->phones($b));
        if ([] !== $sharedPhones) {
            return $this->finalize($a, $b, 95, ['same phone number'], true);
        }

        $sameCompany = $this->sameCompany($a, $b);
        $sameCity = $this->sameCity($a, $b);

        // Tier 3: same name + same company -> 85
        if ('' !== $nameA && $nameA === $nameB && $sameCompany) {
            return $this->finalize($a, $b, 85, ['same name and company'], false);
        }

        // Tier 4: nickname/middle/initial variant + same company|city -> 70-84
        if ('' !== $nameA && '' !== $nameB && ($sameCompany || $sameCity) && $this->isNameVariant($a, $b)) {
            $confidence = ($sameCompany && $sameCity) ? 84 : ($sameCompany ? 80 : 74);
            $reasons = ['nickname variant'];
            if ($sameCompany) {
                $reasons[] = 'same company';
            }
            if ($sameCity) {
                $reasons[] = 'same city';
            }

            return $this->finalize($a, $b, $confidence, $reasons, false);
        }

        // Tier 5: fuzzy name (levenshtein <= 2) + same company|city -> 60-69
        if ('' !== $nameA && '' !== $nameB
            && ($sameCompany || $sameCity)
            && levenshtein($nameA, $nameB) <= 2
        ) {
            $confidence = ($sameCompany && $sameCity) ? 68 : ($sameCompany ? 65 : 62);
            $reasons = ['similar name'];
            if ($sameCompany) {
                $reasons[] = 'same company';
            }
            if ($sameCity) {
                $reasons[] = 'same city';
            }

            return $this->finalize($a, $b, $confidence, $reasons, false);
        }

        return null;
    }

    /**
     * Applies the shared-inbox and Jr./Sr. caution rules.
     */
    private function finalize(Contact $a, Contact $b, int $confidence, array $reasons, bool $autoMergeable): DuplicatePair
    {
        $shared = $this->firstSharedEmail($a, $b);
        if (null !== $shared) {
            $autoMergeable = false;
            $reasons[] = sprintf('shared inbox (%s) — check carefully', $shared);
        }
        if ($this->hasJrSr($a->displayName) || $this->hasJrSr($b->displayName)) {
            $autoMergeable = false;
            $reasons[] = 'Jr./Sr. — check carefully';
        }

        return new DuplicatePair($a, $b, $confidence, $reasons, $autoMergeable);
    }

    /**
     * @return list<string>
     */
    private function emails(Contact $contact): array
    {
        $out = [];
        foreach ($contact->emails as $email) {
            $addr = AddressHelper::email($email->email);
            if ('' !== $addr && !in_array($addr, $out, true)) {
                $out[] = $addr;
            }
        }

        return $out;
    }

    /**
     * @return list<string>  digits only, leading + stripped
     */
    private function phones(Contact $contact): array
    {
        $out = [];
        foreach ($contact->phones as $phone) {
            $digits = preg_replace('/\D+/', '', (string) $phone->phone) ?? '';
            if ('' !== $digits && !in_array($digits, $out, true)) {
                $out[] = $digits;
            }
        }

        return $out;
    }

    private function norm(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function sameCompany(Contact $a, Contact $b): bool
    {
        $ca = $this->norm($a->company);
        $cb = $this->norm($b->company);

        return '' !== $ca && $ca === $cb;
    }

    /**
     * @return list<string>
     */
    private function cities(Contact $contact): array
    {
        $out = [];
        foreach ($contact->addresses as $address) {
            $city = $this->norm($address->city);
            if ('' !== $city && !in_array($city, $out, true)) {
                $out[] = $city;
            }
        }

        return $out;
    }

    private function sameCity(Contact $a, Contact $b): bool
    {
        return [] !== array_intersect($this->cities($a), $this->cities($b));
    }

    /**
     * Nickname map (bidirectional) or one givenName being an initial of the
     * other, with matching family names.
     */
    private function isNameVariant(Contact $a, Contact $b): bool
    {
        $givenA = $this->normName($a->givenName);
        $givenB = $this->normName($b->givenName);
        $familyA = $this->norm($a->familyName);
        $familyB = $this->norm($b->familyName);

        if ('' === $givenA || '' === $givenB || '' === $familyA || $familyA !== $familyB) {
            return false;
        }

        $canonA = self::NICKNAMES[$givenA] ?? $givenA;
        $canonB = self::NICKNAMES[$givenB] ?? $givenB;
        if ($canonA === $canonB) {
            return true;
        }

        // Initial: 'J' vs 'John'
        if (1 === mb_strlen($givenA) && str_starts_with($givenB, $givenA)) {
            return true;
        }
        if (1 === mb_strlen($givenB) && str_starts_with($givenA, $givenB)) {
            return true;
        }

        return false;
    }

    /**
     * Lowercase, dots removed: 'J.' -> 'j'.
     */
    private function normName(?string $value): string
    {
        return str_replace('.', '', $this->norm($value));
    }

    private function hasJrSr(string $displayName): bool
    {
        return 1 === preg_match('/\b(jr|sr)\.?$/i', trim($displayName));
    }

    /**
     * First shared-inbox address on either contact, or null.
     */
    private function firstSharedEmail(Contact $a, Contact $b): ?string
    {
        foreach (array_merge($this->emails($a), $this->emails($b)) as $email) {
            $at = strrpos($email, '@');
            if (false === $at) {
                continue;
            }
            $local = substr($email, 0, $at);
            if (in_array($local, self::SHARED_LOCAL_PARTS, true)) {
                return $email;
            }
        }

        return null;
    }
}
