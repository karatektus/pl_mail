<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\CharsetHelper;
use App\Entity\Mail\Message;
use Psr\Log\LoggerInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Produces the render-ready, isolated HTML stored on Message::bodyHtmlSafe.
 *
 * Runs once at ingest (both syncers), AFTER the message and its MessageParts
 * have been flushed — inline `cid:` refs need the parts' IDs to resolve.
 *
 * The pipeline mirrors how Gmail renders mail inline in its own DOM rather than
 * in an iframe:
 *   1. Re-tag the document's own charset declaration, which the ingest
 *      conversion invalidated and both parsers below would otherwise obey.
 *   2. Resolve `cid:` references (img src + url() in CSS) to our lazy
 *      attachment route.
 *   3. Flatten <style> blocks onto elements as inline styles — the inline
 *      styles become the sole carrier of the email's visual design.
 *   4. Sanitize: drop scripts / forms / iframes / <style> / classes, force
 *      links to open away from the app, keep the inline styles.
 */
final readonly class MailBodySanitizer
{
    /**
     * Symfony's HtmlSanitizer truncates input past this length. The default is
     * 20 KB, which most real emails exceed — set generously (Gmail itself clips
     * around 102 KB).
     */
    private const int MAX_INPUT_LENGTH = 2_000_000;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface       $logger,
    )
    {
    }

    public function sanitize(Message $message): void
    {
        $html = $message->bodyHtml;

        if (null === $html || '' === trim($html)) {
            $message->bodyHtmlSafe = null;

            return;
        }

        // First, before anything parses it. bodyHtml is UTF-8 — the MIME layer
        // saw to that — but the sender's own `<meta charset=iso-8859-1>` came
        // through the conversion unchanged and now contradicts the bytes.
        // Symfony's sanitizer parses with Dom\HTMLDocument, which follows the
        // HTML encoding sniffing algorithm and believes that tag over any
        // default: "über" came back out as "Ã¼ber". Correcting the declaration
        // is what the specification asks of anything that transcodes a
        // document, and it is the same argument as CharsetHelper's bytes over
        // labels, one layer further down.
        $html = CharsetHelper::retagHtmlAsUtf8($html);
        $html = $this->resolveCids($html, $message);
        $html = $this->inlineStyles($html);
        $html = $this->buildSanitizer()->sanitize($html);

        $message->bodyHtmlSafe = $html;
    }

    /**
     * The same sanitiser, over a fragment that belongs to no Message.
     *
     * Exists for the signature: it is HTML the user composes in a
     * contenteditable and the server then injects into every message they
     * send, so it is untrusted input twice over — once when it is written
     * (paste carries whatever the clipboard held) and once when it is read
     * back out of jsonb, which anything with database access can have edited.
     * Running it through the same allow-list as inbound mail means a signature
     * cannot carry a script, a form or an iframe into the composer.
     *
     * No cid resolution and no CSS inlining: a fragment has no message to
     * resolve `cid:` against, and the editor already writes inline styles.
     *
     * Note that the allow-list drops `class` and every `data-` attribute, so a
     * sanitised signature can never carry the `data-pl-signature` marker or a
     * `data-cid` of its own — the wrapper is added around the result, and
     * InlineImageRewriter therefore never sees an image inside a signature as
     * one of its own.
     */
    public function sanitizeFragment(?string $html): string
    {
        if (null === $html || '' === trim($html)) {
            return '';
        }

        return trim($this->buildSanitizer()->sanitize(CharsetHelper::retagHtmlAsUtf8($html)));
    }

    /**
     * Rewrite every `cid:` reference — img src and url(...) inside CSS — to the
     * lazy attachment route. Absolute-PATH (not URL) so no request/host context
     * is needed inside the worker.
     */
    private function resolveCids(string $html, Message $message): string
    {
        $map = [];

        foreach ($message->messageParts as $part) {
            $cid = $part->contentId;

            if (null === $cid || '' === $cid) {
                continue;
            }

            $map[strtolower($cid)] = $this->urlGenerator->generate(
                'app_mail_attachment',
                ['id' => $part->id],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            );
        }

        if (count($map) === 0) {
            return $html;
        }

        return (string)preg_replace_callback(
            '/cid:([^"\'\)\s>]+)/i',
            static function (array $m) use ($map): string {
                $cid = strtolower(trim($m[1]));

                return $map[$cid] ?? ('cid:' . $m[1]);
            },
            $html,
        );
    }

    private function inlineStyles(string $html): string
    {
        // CssToInlineStyles converts each selector to XPath and runs it. An exotic
        // or malformed selector yields invalid XPath, which DOMXPath emits as a
        // warning — and Symfony's handler promotes that to an exception, aborting
        // inlining for the entire message. Swallow warnings for the duration so the
        // library skips only the offending selector; genuine failures still fall
        // through to the catch and sanitize the raw body.
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return new CssToInlineStyles()->convert($html);
        } catch (\Throwable $e) {
            $this->logger->warning('MailBodySanitizer: CSS inlining failed, sanitizing raw body', [
                'error' => $e->getMessage(),
            ]);

            return $html;
        } finally {
            restore_error_handler();
        }
    }

    private function buildSanitizer(): HtmlSanitizer
    {
        $config = new HtmlSanitizerConfig()
            // Structurally-safe baseline (text, lists, tables, …).
            ->allowSafeElements()
            // Drop these entirely — content included — so nothing leaks as text.
            // <style> is redundant after inlining; the rest are never wanted.
            ->dropElement('head')
            ->dropElement('title')
            ->dropElement('style')
            ->dropElement('script')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('form')
            // Images: our resolved attachment route + remote/data sources.
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'style'])
            // Links, forced to open away from the app.
            ->allowElement('a', ['href', 'title', 'style'])
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            // Presentational table attributes emails still lean on.
            ->allowAttribute('bgcolor', '*')
            ->allowAttribute('align', '*')
            ->allowAttribute('valign', '*')
            ->allowAttribute('width', '*')
            ->allowAttribute('height', '*')
            // The inlined styles ARE the visual design — must survive on every
            // element the inliner touched (td, span, div, p, …). Deliberately
            // NOT allowing class/id: stripping them is Gmail-faithful and avoids
            // email selectors colliding with the app's own CSS in the shared DOM.
            ->allowAttribute('style', '*')
            // URL policy. http stays allowed; upgrading/proxying remote images
            // is a render-time concern (image proxy), not an ingest one.
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['https', 'http', 'data'])
            ->allowRelativeMedias()
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        return new HtmlSanitizer($config);
    }
}
