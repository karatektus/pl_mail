<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Dom\HTMLDocument;
use DOMDocument;
use Throwable;
use TijsVerkoyen\CssToInlineStyles\Css\Property\Property;
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
 * A subclass rather than a fork: the library keeps the parse in a method of
 * its own, and the one other thing that changes — how a stylesheet
 * `!important` meets an inline style — in another.
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
            self::renameForms($modern);

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
     * Inline styles outrank the stylesheet, except where the stylesheet says
     * `!important` and the inline style does not. That is the cascade, and the
     * library implements only the first half: it keeps an existing inline
     * property whatever the rule says.
     *
     * Mail leans on the second half to choose between variants. A Bazaarvoice
     * review request marks every product card `class="d" style="display:none"`
     * and switches it on with `.d {display:block !important}`, so a client that
     * cannot read the stylesheet shows nothing and every other client shows
     * the card. Inlined the library's way, the card stayed hidden next to its
     * Outlook twin, which `.c {display:none !important}` hides on purpose: no
     * product photo at all, though the proxy fetched it fine (#271).
     *
     * @param \DOMElement $element
     * @param Property[]  $properties
     *
     * @return \DOMElement
     */
    public function inlineCssOnElement(\DOMElement $element, array $properties)
    {
        if ([] === $properties) {
            return $element;
        }

        $inline = [];

        foreach ($this->getInlineStyles($element) as $property) {
            $inline[$property->getName()] = $property;
        }

        $fromSheet = [];

        foreach ($properties as $property) {
            $existing = $inline[$property->getName()] ?? null;

            if (null === $existing) {
                $fromSheet[$property->getName()] = $property;

                continue;
            }

            // Replaced in place, so the declaration keeps its position.
            if (true === $property->isImportant() && false === $existing->isImportant()) {
                $inline[$property->getName()] = $property;
            }
        }

        $element->setAttribute('style', implode(' ', array_map(
            static fn (Property $property): string => $property->toString(),
            array_values(array_merge($fromSheet, $inline)),
        )));

        return $element;
    }

    /**
     * Every `<form>` becomes a `<div>` before a selector is matched.
     *
     * The sanitizer unwraps forms, so the reader sees the content of one and
     * never the form itself — and the stylesheet has to be applied to the
     * document the reader will see. Mail written with forms in it says what a
     * client like that should show: `form .a {display:none}` and its kin only
     * apply where the form survives, and the sender's fallback is what is left
     * standing when they do not match. Gmail, Thunderbird and Outlook.com all
     * take that fallback; inlined with the form still in place, plMail took
     * the variant meant for clients that can submit it, and lost it with the
     * controls (#271).
     *
     * Over a snapshot, for the same reason as removeComments().
     */
    private static function renameForms(HTMLDocument $document): void
    {
        $forms = [];

        foreach ($document->querySelectorAll('form') as $form) {
            $forms[] = $form;
        }

        foreach ($forms as $form) {
            $div = $document->createElement('div');

            // What a selector or the cascade can use, and nothing a form needs:
            // `action` and `method` are on the sanitizer's safe list for any
            // element and would otherwise ride along on the div.
            foreach (['id', 'class', 'style'] as $name) {
                if (true === $form->hasAttribute($name)) {
                    $div->setAttribute($name, (string) $form->getAttribute($name));
                }
            }

            while (null !== $form->firstChild) {
                $div->appendChild($form->firstChild);
            }

            $form->replaceWith($div);
        }
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
