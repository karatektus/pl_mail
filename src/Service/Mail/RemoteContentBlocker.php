<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Psr\Log\LoggerInterface;

/**
 * Blocks — or, on request, proxies — everything in a message body that would
 * otherwise make the reader's browser talk to the sender's servers.
 *
 * WHY THIS RUNS AT RENDER TIME AND NOT AT INGEST
 * ----------------------------------------------
 * MailBodySanitizer is the obvious home for it, and it is the wrong one twice
 * over. Every message already in the database was sanitized before this
 * existed, so an ingest-time block would leave the entire existing corpus
 * loading tracking pixels — a privacy fix that only protects mail that has not
 * arrived yet is not a fix. And the decision is not a property of the message:
 * the same body is blocked for one reader and allowed for another, because
 * "always show images from this sender" is per user. A stored form cannot carry
 * an answer that depends on who is asking.
 *
 * So bodyHtmlSafe stays exactly what it was — safe markup with the sender's own
 * URLs — and this runs over it on the way to the template.
 *
 * WHAT COUNTS AS REMOTE
 * ---------------------
 * An `img` whose src is absolute http(s), or protocol-relative (`//host/…`).
 * Anything else has already been resolved to us or carries its own bytes:
 * `cid:` inline images were rewritten to our attachment route at ingest and are
 * left completely alone, and `data:` URIs make no request. CSS `url()` inside
 * an inline style gets the same treatment — a background image is a tracking
 * pixel that happens to be styled, and MailBodySanitizer's CSS inlining moves
 * every `<style>` rule onto a style attribute, so this is where they all end up.
 */
final readonly class RemoteContentBlocker
{
    /**
     * A 1×1 transparent GIF. Blocked images keep their `width`/`height`
     * attributes, so substituting real bytes rather than dropping `src` holds
     * the sender's layout together and avoids a page of broken-image icons.
     */
    private const string PLACEHOLDER =
        'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        private ImageProxySigner $signer,
        private LoggerInterface  $logger,
    ) {
    }

    /**
     * @param bool $allowRemote true renders the images, still through the proxy
     *                          — an opted-in load must not expose the reader's
     *                          IP either, which is the whole point of having a
     *                          proxy rather than just unblocking the src.
     */
    public function rewrite(?string $html, bool $allowRemote): RemoteContent
    {
        if (null === $html || '' === trim($html)) {
            return new RemoteContent('', 0, 0);
        }

        try {
            // LIBXML_NOERROR only. Dom\HTMLDocument accepts a SHORT list of
            // flags — NOERROR, COMPACT, HTML_NOIMPLIED, HTML_NO_DEFAULT_NS —
            // and rejects anything else with a ValueError rather than ignoring
            // it. Passing LIBXML_NOWARNING alongside it, which is habit from
            // the old DOMDocument, made every single body take the fail-closed
            // path below: mail rendered as plain text, correctly private and
            // completely unreadable.
            //
            // The encoding override is not optional either. This markup has no
            // <meta charset> — MailBodySanitizer drops <head> — so without it
            // the parser sniffs, and UTF-8 bodies come back mojibaked.
            $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        } catch (\Throwable $exception) {
            // Fail CLOSED. A body this cannot parse is a body whose remote
            // references cannot be found, and rendering it unchanged would load
            // every one of them. The reader gets the text; the pixels do not
            // get the reader.
            $this->logger->warning('RemoteContentBlocker: body did not parse, rendering text only', [
                'error' => $exception->getMessage(),
            ]);

            return new RemoteContent(strip_tags($html), 0, 0);
        }

        $body = $document->body;

        if (null === $body) {
            return new RemoteContent('', 0, 0);
        }

        $touched = $this->rewriteImages($document, $allowRemote)
            + $this->rewriteStyles($document, $allowRemote);

        return new RemoteContent(
            $body->innerHTML,
            true === $allowRemote ? 0 : $touched,
            true === $allowRemote ? $touched : 0,
        );
    }

    private function rewriteImages(\Dom\HTMLDocument $document, bool $allowRemote): int
    {
        $touched = 0;

        foreach ($document->querySelectorAll('img[src]') as $img) {
            if (false === $img instanceof \Dom\Element) {
                continue;
            }

            $src = trim($img->getAttribute('src') ?? '');

            if (null === $absolute = self::remoteUrl($src)) {
                continue;
            }

            $proxied = $this->signer->proxyUrl($absolute);

            if (true === $allowRemote) {
                $img->setAttribute('src', $proxied);
                $touched++;

                continue;
            }

            $img->setAttribute('src', self::PLACEHOLDER);
            // The proxy URL, not the sender's — so that un-blocking in the
            // browser cannot accidentally reintroduce a direct connection, no
            // matter what the client-side code does with this attribute.
            $img->setAttribute('data-plmail-src', $proxied);
            $img->setAttribute('data-plmail-blocked', '1');
            // Something has to be visible where a blocked image was, or a
            // catalogue of products reads as a blank page. The host is also the
            // one piece of information a reader might actually judge on.
            $img->setAttribute('alt', $img->getAttribute('alt') ?: (self::hostOf($absolute) ?? ''));

            $touched++;
        }

        return $touched;
    }

    /**
     * CSS `url()` references inside inline styles.
     *
     * The live `style` keeps everything except the remote reference, which
     * becomes `none` — valid in every shorthand it can appear in, so the rest
     * of the declaration (repeat, position, colour) survives intact. The
     * proxied original is parked on `data-plmail-style` for the un-block.
     */
    private function rewriteStyles(\Dom\HTMLDocument $document, bool $allowRemote): int
    {
        $touched = 0;

        foreach ($document->querySelectorAll('[style]') as $element) {
            if (false === $element instanceof \Dom\Element) {
                continue;
            }

            $style = $element->getAttribute('style') ?? '';

            if ('' === $style || false === stripos($style, 'url(')) {
                continue;
            }

            $found    = 0;
            $proxied  = $this->mapCssUrls($style, fn (string $url): string => $this->signer->proxyUrl($url), $found);

            if (0 === $found) {
                continue;
            }

            if (true === $allowRemote) {
                $element->setAttribute('style', $proxied);
                $touched += $found;

                continue;
            }

            $ignored = 0;
            $element->setAttribute('style', $this->mapCssUrls($style, static fn (): string => '', $ignored, true));
            $element->setAttribute('data-plmail-style', $proxied);
            $element->setAttribute('data-plmail-blocked', '1');

            $touched += $found;
        }

        return $touched;
    }

    /**
     * Rewrites every REMOTE `url(...)` in a declaration list through $map,
     * counting them. `$replaceWholeFunction` swaps out `url(...)` entirely
     * rather than just its argument, which is how a blocked reference becomes
     * `none`.
     *
     * @param callable(string): string $map
     */
    private function mapCssUrls(
        string   $style,
        callable $map,
        int      &$found,
        bool     $replaceWholeFunction = false,
    ): string {
        $found = 0;

        $result = preg_replace_callback(
            '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
            function (array $m) use ($map, &$found, $replaceWholeFunction): string {
                $absolute = self::remoteUrl(trim($m[2]));

                if (null === $absolute) {
                    return $m[0];
                }

                $found++;

                if (true === $replaceWholeFunction) {
                    return 'none';
                }

                return sprintf('url("%s")', str_replace('"', '%22', $map($absolute)));
            },
            $style,
        );

        return $result ?? $style;
    }

    /**
     * The absolute http(s) URL a reference points at, or null when it points at
     * nothing remote — our own attachment route, a `data:` URI, an unresolved
     * `cid:`, or a relative path.
     */
    private static function remoteUrl(string $reference): ?string
    {
        if ('' === $reference) {
            return null;
        }

        // Protocol-relative. MailBodySanitizer allows relative medias, so these
        // survive sanitizing and are as remote as anything with a scheme on it.
        if (true === str_starts_with($reference, '//')) {
            return 'https:' . $reference;
        }

        $scheme = strtolower((string) parse_url($reference, PHP_URL_SCHEME));

        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        return $reference;
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return true === is_string($host) && '' !== $host ? $host : null;
    }
}
