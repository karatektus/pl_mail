<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Dom\HTMLDocument;
use DOMDocument;
use Throwable;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Flattens a mail's stylesheet onto its elements without rearranging the mail.
 *
 * CssToInlineStyles reads the body with DOMDocument::loadHTML, which is
 * libxml's HTML 4 parser, and HTML 4 says an `<a>` cannot contain a table. So
 * that parser ends the link where the table begins: a job alert whose every
 * card is one link wrapped around a table came out as an empty link followed
 * by a card that went nowhere. Nothing reported it — the href survived, on an
 * element with nothing in it — and the same mail was fine in a browser, which
 * parses by the HTML5 rules, where a link may wrap whatever it likes.
 *
 * Every other step that touches a body already parses with Dom\HTMLDocument
 * (the sanitizer, RemoteContentBlocker, QuoteCollapser); this was the one that
 * did not, and it runs first. The library still needs the old DOMDocument for
 * its XPath work, so the tree is built by the HTML5 parser and handed over as
 * XML, which the old class loads exactly as written. The HTML 4 parser never
 * sees the markup and has nothing to re-nest.
 *
 * A subclass rather than a fork: the parse is the one thing that changes, and
 * the library keeps it in a method of its own.
 */
final class Html5CssInliner extends CssToInlineStyles
{
    /**
     * The namespace the HTML5 parser puts every element in. Left on, the
     * library's selectors — plain `descendant-or-self::p` — match nothing at
     * all, because in XPath an unprefixed name means "in no namespace", and
     * every rule in the stylesheet is silently dropped.
     */
    private const string XHTML_NAMESPACE = '/(<html\b[^>]*?)\sxmlns="http:\/\/www\.w3\.org\/1999\/xhtml"/';

    /**
     * @param string $html
     */
    protected function createDomDocumentFromHtml($html): DOMDocument
    {
        try {
            $modern = HTMLDocument::createFromString((string) $html, LIBXML_NOERROR, 'UTF-8');
            $xml    = (string) preg_replace(self::XHTML_NAMESPACE, '$1', (string) $modern->saveXml(), 1);

            $document = new DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);

            try {
                $loaded = $document->loadXML($xml);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }

            if (true === $loaded && null !== $document->documentElement) {
                return $document;
            }
        } catch (Throwable) {
            // Falls through to the library's own parse.
        }

        // What ran before this class existed. A body the hand-over cannot
        // carry — a control character XML forbids, say — is still inlined and
        // still rendered, with the old parser's nesting; worse than the above
        // and far better than a message with no body.
        return parent::createDomDocumentFromHtml($html);
    }
}
