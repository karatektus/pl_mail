<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Message;
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
 *   1. Resolve `cid:` references (img src + url() in CSS) to our lazy
 *      attachment route.
 *   2. Flatten <style> blocks onto elements as inline styles — the inline
 *      styles become the sole carrier of the email's visual design.
 *   3. Sanitize: drop scripts / forms / iframes / <style> / classes, force
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
        $html = $message->getBodyHtml();

        if (null === $html || '' === trim($html)) {
            $message->setBodyHtmlSafe(null);

            return;
        }

        $html = $this->resolveCids($html, $message);
        $html = $this->inlineStyles($html);
        $html = $this->buildSanitizer()->sanitize($html);

        $message->setBodyHtmlSafe($html);
    }

    /**
     * Rewrite every `cid:` reference — img src and url(...) inside CSS — to the
     * lazy attachment route. Absolute-PATH (not URL) so no request/host context
     * is needed inside the worker.
     */
    private function resolveCids(string $html, Message $message): string
    {
        $map = [];

        foreach ($message->getMessageParts() as $part) {
            $cid = $part->getContentId();

            if (null === $cid || '' === $cid) {
                continue;
            }

            $map[strtolower($cid)] = $this->urlGenerator->generate(
                'app_mail_attachment',
                ['id' => $part->getId()],
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
