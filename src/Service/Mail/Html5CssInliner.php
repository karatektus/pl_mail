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
        $modern = null;

        try {
            $modern = HTMLDocument::createFromString((string) $html, LIBXML_NOERROR, 'UTF-8');
            self::removeComments($modern);

            $xml = (string) preg_replace(self::XHTML_NAMESPACE, '$1', (string) $modern->saveXml(), 1);

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
        //
        // Handed the HTML5 parser's own serialisation where there is one,
        // not the sender's markup: that is the copy with the comments already
        // gone, so the HTML 4 parser never meets a conditional-comment marker
        // it would turn into text.
        return parent::createDomDocumentFromHtml(null !== $modern ? $modern->saveHtml() : $html);
    }

    /**
     * Take every comment out of the tree before it is handed over as XML.
     *
     * The handover is what comments broke. XML forbids `--` inside a comment
     * and HTML does not, so one comment holding a double hyphen made loadXML
     * refuse the whole body and sent it to the HTML 4 parser below — with the
     * nesting that parser gets wrong, and with Outlook's conditional comments
     * in it. The downlevel-revealed form, `<!--[if !mso]><!-->` … `<![endif]-->`,
     * ends in a marker that is not inside a comment at all: the HTML5 parser
     * reads it, as a browser does, as a comment holding `[endif]--`, which is
     * exactly what XML refuses; the HTML 4 parser reads it as text, and the
     * stored body showed `<![endif]-->` to the reader (#175).
     *
     * Removed rather than repaired, because nothing downstream keeps one:
     * the sanitizer drops every comment, and the library matches selectors
     * against elements. A conditional comment's Outlook-only content goes with
     * it, which is right for a client that is not Outlook; the content meant
     * for every other client was never inside a comment and stays.
     *
     * Over a snapshot of the nodes, since removing a node while the XPath
     * result is being walked would skip its siblings.
     */
    private static function removeComments(HTMLDocument $document): void
    {
        $comments = [];

        foreach (new \Dom\XPath($document)->query('//comment()') as $comment) {
            $comments[] = $comment;
        }

        foreach ($comments as $comment) {
            $comment->parentNode?->removeChild($comment);
        }
    }
}
