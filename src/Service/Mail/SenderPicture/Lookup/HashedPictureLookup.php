<?php

declare(strict_types=1);

namespace App\Service\Mail\SenderPicture\Lookup;

use App\Domain\DTO\Mail\FetchedPicture;
use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Helper\SenderPictureSubject;
use App\Domain\Interface\SenderPictureLookupInterface;
use App\Infrastructure\Dns\DnsResolver;
use App\Service\Mail\SenderPicture\SenderPictureHttp;

/**
 * A person's picture by the hash of their address: Libravatar where the
 * address's domain runs its own server, then Gravatar (#639).
 *
 * **Libravatar first, because it is the domain's own answer.** A domain that
 * publishes `_avatars-sec._tcp` has said where its people's pictures live; a
 * company or a self-hoster running their own avatar server is the reason the
 * protocol exists. Gravatar is still asked when that server has nothing,
 * because somebody at such a domain may well have a Gravatar and not have
 * uploaded anything at work.
 *
 * libravatar.org itself is not asked. It falls back to Gravatar for anyone it
 * does not know, so it would be a second request to reach the same answer.
 *
 * **SHA-256, not MD5.** Both services accept it, and an MD5 of an address is
 * reversed by a lookup table in an afternoon. SHA-256 is not much better for
 * a guessable address, which is why this source is off until a user asks for
 * it (SenderPictureKind::onByDefault()).
 *
 * `d=404`, so "no picture" is an HTTP status rather than a generated
 * placeholder that would look like a picture to everything downstream.
 */
final readonly class HashedPictureLookup implements SenderPictureLookupInterface
{
    /** Pixels asked for; SenderPictureImage scales to its own size anyway. */
    private const int SIZE = 128;

    private const int MAX_BYTES = 1024 * 1024;

    private const string GRAVATAR = 'https://gravatar.com/avatar/%s?s=%d&d=404';

    public function __construct(
        private SenderPictureHttp $http,
        private DnsResolver       $dns,
    ) {}

    public function kind(): SenderPictureKind
    {
        return SenderPictureKind::Hashed;
    }

    public function lookup(string $subject): ?FetchedPicture
    {
        $hash = hash('sha256', $subject);

        $federated = $this->federatedServer($subject);

        if (null !== $federated) {
            $fetched = $this->http->get(
                sprintf('https://%s/avatar/%s?s=%d&d=404', $federated, $hash, self::SIZE),
                ['image/'],
                self::MAX_BYTES,
            );

            if (null !== $fetched) {
                return new FetchedPicture($fetched['body'], $fetched['contentType'], 'libravatar:' . $federated);
            }
        }

        $fetched = $this->http->get(sprintf(self::GRAVATAR, $hash, self::SIZE), ['image/'], self::MAX_BYTES);

        return null === $fetched ? null : new FetchedPicture($fetched['body'], $fetched['contentType'], 'gravatar');
    }

    /**
     * The domain's own Libravatar server, from its secure SRV record. Only one
     * on port 443: SenderPictureHttp speaks https to the default port, and a
     * DNS record is not a reason to let a stranger choose which port of which
     * host this server connects to.
     */
    private function federatedServer(string $address): ?string
    {
        $domain = SenderPictureSubject::domainOf($address);

        if (null === $domain) {
            return null;
        }

        foreach ($this->dns->srv('_avatars-sec._tcp.' . $domain) as $record) {
            if (443 === $record['port'] && '' !== $record['target'] && 1 === preg_match('/^[a-z0-9.-]+$/i', $record['target'])) {
                return strtolower($record['target']);
            }
        }

        return null;
    }
}
