<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use App\Domain\Enum\Mail\SenderPictureKind;

/**
 * What a sender picture lookup is keyed by, worked out from a From address
 * (#639).
 *
 * One function for every caller — the ingest step that queues lookups, the
 * page that reads them back, the live update that finds rows on screen — so
 * that "the subject of a BIMI lookup" cannot mean the full domain in one place
 * and the registrable one in another. A mismatch would not fail: the lookup
 * would be stored under one key and searched for under the other, and every
 * picture it found would be invisible.
 *
 * See SenderPictureKind for why each kind is keyed the way it is.
 */
final class SenderPictureSubject
{
    /**
     * The lookup key for $kind, or null when the address cannot have one — no
     * address, no domain, an IP literal — or when the kind is not a lookup.
     */
    public static function of(SenderPictureKind $kind, ?string $address): ?string
    {
        $email = AddressHelper::email($address);

        if (false === AddressHelper::isValidEmail($email)) {
            return null;
        }

        return match ($kind) {
            SenderPictureKind::Hashed  => $email,
            SenderPictureKind::Bimi    => self::domainOf($email),
            SenderPictureKind::Website => DomainHelper::registrableOfAddress($email),
            SenderPictureKind::LocalUser, SenderPictureKind::Contact => null,
        };
    }

    /**
     * The address as the page names it to the live update: a hash, so the
     * markup carries a key to match on rather than a second copy of every
     * address on the page, and so the Mercure payload names nobody.
     *
     * Truncated, because it is a lookup key within one reader's page and
     * not a security boundary; thirty-two hex digits cannot collide by
     * accident in a list of fifty rows.
     */
    public static function addressKey(?string $address): ?string
    {
        $email = AddressHelper::email($address);

        if ('' === $email) {
            return null;
        }

        return substr(hash('sha256', $email), 0, 32);
    }

    /** The host part, lowercased; null when there is none or it is an IP literal. */
    public static function domainOf(?string $address): ?string
    {
        $email = AddressHelper::email($address);
        $at    = strrpos($email, '@');

        if (false === $at) {
            return null;
        }

        $domain = trim(substr($email, $at + 1), " .[]");

        if ('' === $domain || false === str_contains($domain, '.') || false !== filter_var($domain, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $domain;
    }
}
