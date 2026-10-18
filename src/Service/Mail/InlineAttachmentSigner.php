<?php

declare(strict_types=1);

namespace App\Service\Mail;

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
 * What the signature authorises, exactly: reading ONE part, by id, as an image.
 * It carries no user and no message, so it is a capability for that part and
 * nothing else. It is minted only by {@see InlineAttachmentLinker}, which signs
 * the parts of the message being rendered for a reader who is allowed to see
 * it, and the route behind it still refuses anything that is not an inline
 * raster type.
 *
 * The payload is prefixed so a signature minted here can never be replayed as
 * one for the image proxy, which signs a bare URL with the same secret.
 */
final readonly class InlineAttachmentSigner
{
    private const int SIGNATURE_LENGTH = 32;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function sign(int $partId): string
    {
        return substr(hash_hmac('sha256', 'inline-attachment:' . $partId, $this->secret), 0, self::SIGNATURE_LENGTH);
    }

    public function isValid(int $partId, string $signature): bool
    {
        return hash_equals($this->sign($partId), $signature);
    }

    /**
     * Absolute PATH, like every other URL written into a mail body: no request
     * context is needed, and a relative src inside the frame resolves against
     * the parent document, which is this app.
     */
    public function urlFor(int $partId): string
    {
        return $this->urlGenerator->generate(
            'app_mail_inline_attachment',
            ['id' => $partId, 's' => $this->sign($partId)],
            UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }
}
