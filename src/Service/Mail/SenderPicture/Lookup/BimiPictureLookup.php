<?php

declare(strict_types=1);

namespace App\Service\Mail\SenderPicture\Lookup;

use App\Domain\DTO\Mail\FetchedPicture;
use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Helper\DomainHelper;
use App\Domain\Interface\SenderPictureLookupInterface;
use App\Infrastructure\Dns\DnsResolver;
use App\Service\Mail\SenderPicture\SenderPictureHttp;

/**
 * A domain's BIMI logo (#639): the brand mark a sender publishes in DNS for
 * mail clients to show next to its authenticated mail.
 *
 * **Found the way the draft standard says.** `default._bimi.<from-domain>`
 * first, then the same at the organisational domain. The record is tags —
 * `v=BIMI1; l=https://…/logo.svg; a=…` — and `l=` is the logo. A record with
 * an empty `l=` is a domain declining to show one, which is an answer: there
 * is no logo.
 *
 * **Only behind an enforced DMARC policy**, as BIMI itself requires: `p=` of
 * quarantine or reject, at 100 percent. A domain that has not told receivers
 * to refuse forged mail has not earned a logo on its real mail, because the
 * same domain's forged mail would sail through many receivers and wear it too.
 * And even then a logo is shown only on a message that passed the receiving
 * server's check — that half is SenderPictures', at display time, per message.
 *
 * **No VMC.** The `a=` certificate that Gmail and Apple insist on is not
 * checked: verifying it means an X.509 chain to a handful of mark-certificate
 * authorities, and its job — proving a trademark — is not what a self-hosted
 * reader is asking. The logo is the domain's own claim about itself, shown on
 * mail the domain demonstrably sent. That is less than Gmail shows and says
 * so in the handbook.
 *
 * The SVG is not trusted for a moment: it is sanitised before it is stored
 * (SenderPictureImage) and served under a sandbox policy.
 */
final readonly class BimiPictureLookup implements SenderPictureLookupInterface
{
    /** BIMI's own limit on the logo file. */
    private const int MAX_BYTES = 32 * 1024;

    /**
     * Types an SVG is actually served as. image/svg+xml is right, and the rest
     * are what real brand sites send; SvgSanitizer decides whether the bytes
     * are an SVG, not the header.
     */
    private const array SVG_TYPES = ['image/svg+xml', 'application/xml', 'text/xml', 'text/plain', 'application/octet-stream'];

    public function __construct(
        private SenderPictureHttp $http,
        private DnsResolver       $dns,
    ) {}

    public function kind(): SenderPictureKind
    {
        return SenderPictureKind::Bimi;
    }

    public function lookup(string $subject): ?FetchedPicture
    {
        $organisational = DomainHelper::registrable($subject);

        if (null === $organisational || false === $this->dmarcEnforced($subject, $organisational)) {
            return null;
        }

        $logo = $this->logoUrl($subject) ?? ($organisational !== $subject ? $this->logoUrl($organisational) : null);

        if (null === $logo) {
            return null;
        }

        $fetched = $this->http->get($logo, self::SVG_TYPES, self::MAX_BYTES);

        return null === $fetched ? null : new FetchedPicture($fetched['body'], 'image/svg+xml', $logo);
    }

    /**
     * The `l=` of the domain's BIMI record; null with no record, a record
     * that is not BIMI1, or a declined (empty) logo.
     */
    private function logoUrl(string $domain): ?string
    {
        foreach ($this->dns->txt('default._bimi.' . $domain) as $record) {
            $tags = self::tags($record);

            if ('BIMI1' !== ($tags['v'] ?? null)) {
                continue;
            }

            $location = trim(explode(',', $tags['l'] ?? '')[0]);

            return '' === $location ? null : $location;
        }

        return null;
    }

    /**
     * Whether DMARC tells receivers to refuse this domain's forgeries: a
     * policy of quarantine or reject applied to all of its mail. The domain's
     * own record wins; failing that, the organisational domain's, whose `sp=`
     * covers subdomains where it is set.
     */
    private function dmarcEnforced(string $domain, string $organisational): bool
    {
        $own = $this->dmarc($domain);

        if (null !== $own) {
            return self::enforces($own['p'] ?? null, $own);
        }

        if ($organisational === $domain) {
            return false;
        }

        $parent = $this->dmarc($organisational);

        if (null === $parent) {
            return false;
        }

        return self::enforces($parent['sp'] ?? $parent['p'] ?? null, $parent);
    }

    /** @return array<string, string>|null */
    private function dmarc(string $domain): ?array
    {
        foreach ($this->dns->txt('_dmarc.' . $domain) as $record) {
            $tags = self::tags($record);

            if ('DMARC1' === ($tags['v'] ?? null)) {
                return $tags;
            }
        }

        return null;
    }

    /** @param array<string, string> $tags */
    private static function enforces(?string $policy, array $tags): bool
    {
        $policy = strtolower(trim((string) $policy));

        if ('quarantine' !== $policy && 'reject' !== $policy) {
            return false;
        }

        return '100' === trim($tags['pct'] ?? '100');
    }

    /**
     * `k=v; k=v` into a map. Keys are case-insensitive, as both records' specs
     * say; values are kept as written.
     *
     * @return array<string, string>
     */
    public static function tags(string $record): array
    {
        $tags = [];

        foreach (explode(';', $record) as $pair) {
            $equals = strpos($pair, '=');

            if (false === $equals) {
                continue;
            }

            $key = strtolower(trim(substr($pair, 0, $equals)));

            if ('' !== $key) {
                $tags[$key] = trim(substr($pair, $equals + 1));
            }
        }

        return $tags;
    }
}
