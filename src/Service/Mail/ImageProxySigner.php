<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Mints and checks the signed URLs the image proxy answers on.
 *
 * What the signature IS for: integrity. The proxy takes a target URL in the
 * query string, and without a MAC that parameter is user input the route would
 * hand straight to an HTTP client — so the signature is what makes "this URL
 * came out of a message body we rewrote" checkable.
 *
 * What the signature is NOT for, and it matters that this is written down: it
 * is not the SSRF defence. Anyone with an account can mail themselves a message
 * containing any URL and be handed a valid signature for it, so the signature
 * constrains nothing about *where* the proxy will connect. That job belongs
 * entirely to {@see ImageProxyFetcher}, which validates the resolved address on
 * every hop. The signature only means the parameter was not typed by hand.
 */
final readonly class ImageProxySigner
{
    /**
     * Truncated to 32 hex characters. A MAC's job here is to be unforgeable,
     * not to be a digest, and 128 bits is well past what any offline attack on
     * a per-request parameter could reach.
     */
    private const int SIGNATURE_LENGTH = 32;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function sign(string $url): string
    {
        return substr(hash_hmac('sha256', $url, $this->secret), 0, self::SIGNATURE_LENGTH);
    }

    public function isValid(string $url, string $signature): bool
    {
        return hash_equals($this->sign($url), $signature);
    }

    /**
     * The absolute-PATH proxy URL for a remote image.
     *
     * Path, not URL, for the same reason MailBodySanitizer resolves `cid:` to a
     * path: this runs in template rendering and in workers, and a worker has no
     * request to derive a host from. A relative src inside the reading iframe
     * resolves against the parent document's URL, which is ours.
     */
    public function proxyUrl(string $remoteUrl): string
    {
        return $this->urlGenerator->generate(
            'app_mail_image_proxy',
            ['u' => $remoteUrl, 's' => $this->sign($remoteUrl)],
            UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }
}
