<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Mints and checks the signed URLs an inline image is loaded through.
 *
 * Why an inline image needs a signature at all: the message body renders in a
 * sandbox without `allow-same-origin`, so its origin is opaque and the browser
 * sends no session cookie with the frame's `<img>` loads. Behind `ROLE_USER`
 * every `cid:` image came back as a 302 to the login page and rendered as an
 * empty box. The image proxy hit the same wall and answers on an HMAC instead
 * (see {@see ImageProxySigner}); this is the same design for a part we already
 * hold.
 *
 * What the signature authorises, exactly: reading ONE part, by id, as an image,
 * until it expires. It carries no user and no message, so it is a capability
 * for that part and nothing else. It is minted only by
 * {@see InlineAttachmentLinker}, which signs the parts of the message being
 * rendered for a reader who is allowed to see it, and the route behind it still
 * refuses anything that is not an inline raster image.
 *
 * WHY IT EXPIRES
 * --------------
 * A link without an expiry would outlive the reason it was minted: one that
 * leaks through a forward, a copied link or a log would serve that image to
 * anyone, for good, even after the reader lost access to the mailbox. Inline
 * parts are signatures, receipts and photos, which is more personal than the
 * remote pictures the image proxy fetches. The body is re-rendered, and so
 * re-signed, on every view, so a short lifetime costs a reader nothing.
 *
 * The expiry is the end of the day AFTER the current UTC day, not "now + 24h":
 * every render on the same day mints the same URL, so the browser's cache still
 * answers a message opened twice. A link therefore lives between one and two
 * days.
 *
 * The payload is prefixed so a signature minted here can never be replayed as
 * one for the image proxy, which signs a bare URL with the same secret.
 */
final readonly class InlineAttachmentSigner
{
    private const int SIGNATURE_LENGTH = 32;

    /** One day; a link lives between one and two of them. */
    public const int LIFETIME = 86400;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function sign(int $partId, int $expires): string
    {
        return substr(
            hash_hmac('sha256', 'inline-attachment:' . $partId . ':' . $expires, $this->secret),
            0,
            self::SIGNATURE_LENGTH,
        );
    }

    public function isValid(int $partId, int $expires, string $signature): bool
    {
        return $this->secondsLeft($expires) > 0
            && hash_equals($this->sign($partId, $expires), $signature);
    }

    /** How long a link with this expiry still works; zero or less once it has expired. */
    public function secondsLeft(int $expires): int
    {
        return $expires - $this->clock->now()->getTimestamp();
    }

    /**
     * Absolute PATH, like every other URL written into a mail body: no request
     * context is needed, and a relative src inside the frame resolves against
     * the parent document, which is this app.
     */
    public function urlFor(int $partId): string
    {
        $expires = (intdiv($this->clock->now()->getTimestamp(), self::LIFETIME) + 2) * self::LIFETIME;

        return $this->urlGenerator->generate(
            'app_mail_inline_attachment',
            ['id' => $partId, 'e' => $expires, 's' => $this->sign($partId, $expires)],
            UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }
}
