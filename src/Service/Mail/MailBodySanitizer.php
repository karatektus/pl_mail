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

    /**
     * The attributes a body composed in plMail is allowed to keep, on top of
     * the inbound-mail allow-list.
     *
     * A closed list rather than "any data-*": the point is that the set is
     * known and inert, and a wildcard would readmit whatever a future editor
     * feature — or a paste — happens to write.
     */
    private const array COMPOSE_MARKERS = [
        'data-quoted',
        'data-quote-wrapped',
        'data-quote-toggle',
        'data-pl-signature',
        'data-cid',
    ];

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
     * A body the user composed here, on its way into the database.
     *
     * The same allow-list as inbound mail, because a composed body contains
     * inbound mail: the quote under a reply is the sender's HTML, and until
     * this method existed it went into `bodyHtml` raw, was echoed into the
     * app's own document by the compose window's `|raw`, and ran there. That
     * is the path a reply to a hostile mail took — the read path was sandboxed,
     * the answer path was not.
     *
     * What it adds is the five attributes the composer writes itself. The mail
     * allow-list drops every `data-` attribute, which is right for a stranger's
     * HTML and wrong here: `data-quoted` is how the quote is found — to collapse
     * it, to cut it off the snippet, to tell the user's own writing from the
     * mail they are answering — `data-cid` is the whole bridge between an
     * inline image in the editor and the `cid:` reference that goes on the wire
     * (InlineImageRewriter), and `data-pl-signature` is what a changed From
     * replaces. Sanitising without them would leave the body safe and the
     * features broken: quotes that no longer collapse, snippets containing the
     * whole quoted thread, inline images that arrive as strangers.
     *
     * They are safe to keep because they are inert. None is a URL, none is
     * script, and nothing reads them as anything but a marker to find a node
     * by; an attacker who plants `data-quoted` on their own mail has achieved
     * a collapsed quote.
     *
     * `class` is deliberately NOT among them, even though SignatureProvider
     * writes `class="pl-signature"` beside the marker: nothing styles or
     * queries that class — the attribute is the handle everywhere — and
     * allowing class through would let pasted markup collide with the app's own
     * CSS in a document that, unlike the reading frame, is shared with it.
     */
    public function sanitizeComposedBody(?string $html): string
    {
        if (null === $html || '' === trim($html)) {
            return '';
        }

        return trim($this->restoreCidReferences(
            $this->buildComposeSanitizer()->sanitize(CharsetHelper::retagHtmlAsUtf8($html)),
        ));
    }

    /**
     * Put `cid:` references back the way the wire needs them.
     *
     * The sanitiser escapes `@` to `&#64;` in every attribute value, which is
     * correct HTML and correct in a browser — and fatal here, because a `cid:`
     * reference is matched as a literal string twice on the way out. Symfony's
     * Email::prepareParts() pairs an embedded part to the body by searching for
     * `cid:<name>` and replacing it; InlineAttachmentDetector decides at ingest
     * whether a part is an inline body asset by the same kind of match. Neither
     * parses HTML, so `cid:logo&#64;plmail` pairs with nothing: the image is
     * embedded with a Content-ID the body never mentions, and every mail sent
     * with an inline image goes out with a broken one.
     *
     * Only inside `cid:`, and only when the decoded reference still looks like
     * a reference. Decoding an attribute value in general would be a way back
     * out of it — `&quot;` becoming a quote that ends the attribute early is
     * the whole reason the sanitiser encodes in the first place — so anything
     * that decodes to a quote, an angle bracket, whitespace or another
     * ampersand is left exactly as the sanitiser wrote it.
     */
    private function restoreCidReferences(string $html): string
    {
        if (false === str_contains($html, 'cid:')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/cid:[^"\'\s>)]+/i',
            static function (array $m): string {
                $decoded = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return 1 === preg_match('/^cid:[^"\'<>\s&]+$/', $decoded) ? $decoded : $m[0];
            },
            $html,
        );
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
        return new HtmlSanitizer($this->allowList());
    }

    /**
     * The allow-list plus the composer's own markers. See
     * sanitizeComposedBody() for why each one is on the list.
     */
    private function buildComposeSanitizer(): HtmlSanitizer
    {
        $config = $this->allowList();

        foreach (self::COMPOSE_MARKERS as $marker) {
            // Reassigned, not called for effect: HtmlSanitizerConfig is
            // immutable and every allow* method answers a clone. A bare
            // $config->allowAttribute(...) configures a copy that is then
            // dropped, which looks exactly like a working allow-list and
            // silently strips everything it was supposed to keep.
            $config = $config->allowAttribute($marker, '*');
        }

        // `cid:` on top of the mail schemes, because of WHERE this runs.
        // DraftPersister::save() rewrites the editor's attachment URLs into
        // `cid:` references BEFORE the draft is persisted — the stored body is
        // the one that goes on the wire — so by the time a composed body
        // reaches this sanitiser its inline images already point at cid.
        // Without the scheme they are stripped to src-less <img> tags and every
        // inline image a user attaches disappears on the next autosave.
        return new HtmlSanitizer($config->allowMediaSchemes(['https', 'http', 'data', 'cid']));
    }

    private function allowList(): HtmlSanitizerConfig
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

        return $config;
    }
}
