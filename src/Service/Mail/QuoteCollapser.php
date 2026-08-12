<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Collapses the trailing reply-history of a message body behind a "Show quoted
 * text" toggle, the way Gmail does — but on the READ side, and server-side.
 *
 * WHY HERE, AT RENDER TIME
 * ------------------------
 * The body renders inside an opaque-origin sandboxed iframe (no
 * allow-same-origin), so the parent controller cannot reach into it to hide a
 * region after the fact. The wrap therefore has to be part of the HTML the
 * frame is handed. And it must be a DISPLAY transformation, not an ingest one:
 * doing it here — over the already-sanitized {@see RemoteContentBlocker} output,
 * on the way to the template — means every message already in the database
 * collapses with no reprocessing, no migration, no backfill, and
 * `bodyHtmlSafe` stays the faithful full content.
 *
 * The toggle itself (flipping `[hidden]` on the wrapper and re-reporting the
 * frame height) lives in the frame's nonce'd bootstrap script — see
 * templates/mail/_message_body.html.twig. This class only re-structures markup:
 * it wraps a region and inserts a `<button>`; it never introduces a script or
 * an event handler, so the CSP/sandbox story is untouched.
 *
 * DETECTION — CONSERVATIVE BY DESIGN
 * ----------------------------------
 * A false negative (a quote left visible) is harmless; a false positive (the
 * sender's own new text hidden) is a serious bug. So the bar to collapse is
 * high and, crucially, the sanitizer has already stripped `class`, `id` and
 * `blockquote[type]` — so `gmail_quote`, `divRplyFwdMsg` and `type="cite"` are
 * GONE by the time we see the body. Detection leans on what survives sanitizing:
 * element structure (`<blockquote>`), inline text (attribution lines, Outlook
 * "From:/Sent:/To:/Subject:" header blocks, "-----Original Message-----",
 * forwarded-message dividers).
 *
 * The seam is chosen as the OUTERMOST element whose own leading text is a
 * quote-start marker and none of whose ancestors' leading text is — which is
 * exactly the top of the reply history, with the sender's new text sitting
 * before it. Nothing collapses unless there is genuine new text before that
 * seam, so a body that IS a quote (a bare leading blockquote, a comment-free
 * forward) is left untouched.
 *
 * DELIBERATELY NOT CAUGHT (documented, harmless false negatives):
 *  - A bare trailing `<blockquote>` with NO attribution line and no other
 *    reply signal. Indistinguishable from a genuine block quotation the sender
 *    added, so it is never collapsed. Real replies (Gmail, Apple Mail, Outlook)
 *    all carry an attribution line or a header block, which IS caught.
 *  - Plain-text mails: they never reach here. A message with no HTML body
 *    renders through the `<pre>` branch in the template, not the iframe, so
 *    `>`-quoting is out of scope for this class.
 *  - A comment-free forward/reply (no new text before the seam) — left whole.
 */
final readonly class QuoteCollapser
{
    /**
     * A muted pill, styled inline because the frame is an opaque origin the app
     * cannot style from outside and Tailwind never reaches it. Reads like the
     * compose-side "··· (show quoted text)" affordance: quiet, not a loud
     * button. The "···" is the visible affordance; the accessible name comes
     * from aria-label, swapped by the frame script as the state flips.
     */
    private const string BUTTON_STYLE =
        'display:inline-block;margin:0.4em 0;padding:0.05em 0.6em;'
        . 'font:inherit;font-size:0.85em;line-height:1.5;letter-spacing:0.08em;'
        . 'color:#6b7280;background:transparent;border:1px solid #d1d5db;'
        . 'border-radius:9999px;cursor:pointer;user-select:none;';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string $html already-sanitized, already-image-blocked body HTML
     *                      (a fragment: the frame's `<body>` inner HTML)
     *
     * @return string the same HTML with a trailing reply-history region wrapped
     *                in `<div data-plmail-quote hidden>` behind a
     *                `<button data-plmail-quote-toggle>`, or the input unchanged
     *                when nothing was confidently detected
     */
    public function collapse(string $html): string
    {
        if ('' === trim($html)) {
            return $html;
        }

        try {
            // Same parser the sanitizer and the blocker use — never regex on
            // markup. LIBXML_NOERROR + explicit UTF-8, exactly as the blocker,
            // because this markup has no <meta charset>.
            $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        } catch (\Throwable) {
            // Collapsing is display sugar; a body we cannot parse is simply
            // rendered whole. (The blocker, which runs first, fails closed for
            // its own privacy reasons — by the time we get here the body parsed
            // once already, so this is belt-and-braces.)
            return $html;
        }

        $body = $document->body;

        if (null === $body) {
            return $html;
        }

        // Idempotent: never wrap a body that already carries a toggle.
        if (null !== $body->querySelector('[data-plmail-quote-toggle]')) {
            return $html;
        }

        $boundary = $this->findBoundary($body);

        if (null === $boundary) {
            return $html;
        }

        // The false-positive guard: only a TRAILING region is ever collapsed,
        // so there must be real new text before the seam. Without it we would
        // be hiding the whole visible body — refuse, and render nothing new.
        if ('' === trim($this->textBefore($body, $boundary))) {
            return $html;
        }

        $this->wrap($document, $boundary);

        return $body->innerHTML;
    }

    /**
     * The top of the reply history: the OUTERMOST element whose leading text is
     * a quote-start marker, with no ancestor that is also one. Everything from
     * it to the end of its parent is the quote.
     */
    private function findBoundary(\Dom\Element $body): ?\Dom\Element
    {
        $candidates = [];

        foreach ($body->querySelectorAll('*') as $element) {
            if (false === $element instanceof \Dom\Element) {
                continue;
            }

            if (true === $this->isQuoteStart($element)) {
                $candidates[] = $element;
            }
        }

        if ([] === $candidates) {
            return null;
        }

        // querySelectorAll is document order, so the first candidate with no
        // candidate ancestor is the outermost-earliest one.
        foreach ($candidates as $element) {
            if (false === $this->hasAncestorIn($element, $candidates)) {
                return $element;
            }
        }

        return null;
    }

    /** True when any ancestor of $element (up to the body) is in $others. */
    private function hasAncestorIn(\Dom\Element $element, array $others): bool
    {
        $parent = $element->parentElement;

        while (null !== $parent) {
            foreach ($others as $other) {
                if (true === $parent->isSameNode($other)) {
                    return true;
                }
            }

            $parent = $parent->parentElement;
        }

        return false;
    }

    /**
     * Does this element BEGIN a reply-history region? Judged on its leading
     * text (whitespace-normalised, capped) plus, for the attribution family, a
     * structural check that an actual quote body is attached — an "On … wrote:"
     * that introduces nothing is just prose.
     */
    private function isQuoteStart(\Dom\Element $element): bool
    {
        $lead = $this->leadingText($element);

        if ('' === $lead) {
            return false;
        }

        // Gmail / Apple Mail / most clients: "On <date>, <name> wrote:" and its
        // German/French/Spanish equivalents, but only when a blockquote (the
        // quoted body) actually follows.
        if (true === $this->isAttribution($lead) && true === $this->hasQuoteBody($element)) {
            return true;
        }

        // Outlook / generic client dividers — strong, low-false-positive text.
        if (true === $this->isOriginalMessageDivider($lead)) {
            return true;
        }

        if (true === $this->isForwardedDivider($lead)) {
            return true;
        }

        // Outlook reply: a "From:/Sent:/To:/Subject:" header block (its
        // #divRplyFwdMsg id is long gone by sanitize time).
        if (true === $this->isOutlookHeaderBlock($element, $lead)) {
            return true;
        }

        return false;
    }

    /** Whitespace-collapsed, length-capped flattened text — the "first line". */
    private function leadingText(\Dom\Element $element): string
    {
        return mb_substr($this->flatten($element), 0, 400);
    }

    /**
     * The element's text with every tag turned into a space.
     *
     * Plain `textContent` glues tokens a `<br>` or a block boundary separated
     * on screen — "9:14 AM<br>To:" reads back as "AMTo:", which then hides from
     * a `\bTo:` header probe. Flattening the innerHTML keeps those boundaries as
     * whitespace, so the header labels stay recognisable words.
     */
    private function flatten(\Dom\Element $element): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $element->innerHTML) ?? $element->innerHTML;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * An attribution line that introduces a quote. Names sit before "wrote:" in
     * English/French/Spanish and after "schrieb" in German, hence the two
     * shapes.
     */
    private function isAttribution(string $lead): bool
    {
        return 1 === preg_match(
            '/^(on\b.{1,240}?\bwrote\s*:'
            . '|le\b.{1,240}?\ba\s+écrit\s*:'
            . '|el\b.{1,240}?\bescribió\s*:'
            . '|am\b.{1,240}?\bschrieb\b.{0,160}?:)/isu',
            $lead,
        );
    }

    /** A blockquote lives inside this element, or is (in) its next sibling. */
    private function hasQuoteBody(\Dom\Element $element): bool
    {
        if ('blockquote' === strtolower($element->localName)) {
            return true;
        }

        if (null !== $element->querySelector('blockquote')) {
            return true;
        }

        $next = $element->nextElementSibling;

        while (null !== $next) {
            if ('blockquote' === strtolower($next->localName) || null !== $next->querySelector('blockquote')) {
                return true;
            }

            // Only look past genuinely empty spacers (a stray <br>-only div).
            if ('' !== trim((string) $next->textContent)) {
                return false;
            }

            $next = $next->nextElementSibling;
        }

        return false;
    }

    /** "-----Original Message-----" and the German "Ursprüngliche Nachricht". */
    private function isOriginalMessageDivider(string $lead): bool
    {
        return 1 === preg_match(
            '/^-{2,}\s*(original message|ursprüngliche nachricht)\b/iu',
            $lead,
        );
    }

    /** Gmail's "---------- Forwarded message ---------" and Apple's "Begin forwarded message:". */
    private function isForwardedDivider(string $lead): bool
    {
        return 1 === preg_match('/^-{2,}\s*forwarded message\b/iu', $lead)
            || 1 === preg_match('/^begin forwarded message\s*:/iu', $lead);
    }

    /**
     * An Outlook-style quoted-header block: leading "From:", plus a date, a
     * recipient and a subject line somewhere in the same element. Four labels
     * agreeing is a strong enough signal to stand on its own.
     */
    private function isOutlookHeaderBlock(\Dom\Element $element, string $lead): bool
    {
        if (1 !== preg_match('/^(from|von)\s*:/iu', $lead)) {
            return false;
        }

        $full = $this->flatten($element);

        return 1 === preg_match('/\b(sent|date|gesendet|datum)\s*:/iu', $full)
            && 1 === preg_match('/\b(to|an)\s*:/iu', $full)
            && 1 === preg_match('/\b(subject|betreff)\s*:/iu', $full);
    }

    /**
     * The sender's new text: every text node in document order up to the seam.
     * Ancestor-of-boundary text that appears before it (a "Reply…" line sharing
     * a wrapper with the quote) counts; text inside the boundary does not.
     */
    private function textBefore(\Dom\Element $body, \Dom\Node $boundary): string
    {
        $text = '';

        // Pre-order traversal on an explicit stack — children pushed in reverse
        // so they pop in document order — stopping the instant we reach the
        // seam. The seam's own subtree is never entered, so quoted text is
        // excluded; ancestor text that appears before the seam (a "Reply…" line
        // sharing a wrapper with the quote) is included.
        $stack = self::childrenReversed($body);

        while ([] !== $stack) {
            $node = array_pop($stack);

            if (true === $node->isSameNode($boundary)) {
                break;
            }

            if (\XML_TEXT_NODE === $node->nodeType) {
                $text .= (string) $node->textContent;

                continue;
            }

            foreach (self::childrenReversed($node) as $child) {
                $stack[] = $child;
            }
        }

        return $text;
    }

    /**
     * A node's children last-to-first, so pushing them onto a stack and popping
     * yields document order.
     *
     * @return list<\Dom\Node>
     */
    private static function childrenReversed(\Dom\Node $node): array
    {
        $children = [];

        for ($child = $node->lastChild; null !== $child; $child = $child->previousSibling) {
            $children[] = $child;
        }

        return $children;
    }

    /**
     * Insert the toggle and a hidden wrapper before the seam, then move the seam
     * and every following sibling (the whole trailing region) into the wrapper.
     */
    private function wrap(\Dom\HTMLDocument $document, \Dom\Element $boundary): void
    {
        $parent = $boundary->parentNode;

        if (null === $parent) {
            return;
        }

        $wrapper = $document->createElement('div');
        $wrapper->setAttribute('data-plmail-quote', '');
        $wrapper->setAttribute('hidden', '');

        $parent->insertBefore($this->buildButton($document), $boundary);
        $parent->insertBefore($wrapper, $boundary);

        $node = $boundary;

        while (null !== $node) {
            $next = $node->nextSibling;
            $wrapper->appendChild($node);
            $node = $next;
        }
    }

    private function buildButton(\Dom\HTMLDocument $document): \Dom\Element
    {
        $show = $this->translator->trans('message.quote.show');
        $hide = $this->translator->trans('message.quote.hide');

        $button = $document->createElement('button');
        $button->setAttribute('type', 'button');
        $button->setAttribute('data-plmail-quote-toggle', '');
        // Reflects state for assistive tech; the frame script flips it.
        $button->setAttribute('aria-expanded', 'false');
        // The accessible name — the visible "···" says nothing on its own.
        $button->setAttribute('aria-label', $show);
        $button->setAttribute('title', $show);
        // Both labels travel with the button so the in-frame script can swap
        // them without a second translation round-trip.
        $button->setAttribute('data-label-show', $show);
        $button->setAttribute('data-label-hide', $hide);
        $button->setAttribute('style', self::BUTTON_STYLE);
        $button->textContent = '···';

        return $button;
    }
}
