<?php

declare(strict_types=1);

namespace App\Service\Calendar\Dav;

use Closure;
use XMLWriter;

/**
 * WebDAV's 207 Multi-Status body, which is the answer to nearly every question
 * CalDAV asks — PROPFIND, calendar-query, calendar-multiget and sync-collection
 * all come back in this shape. DavResource, on the client side of this
 * codebase, is the same document being read; this is it being written.
 *
 * ── Namespaces are declared once, on the root ─────────────────────────────
 *
 * Four of them, all on the multistatus element. Declaring them per-element is
 * legal and produces a document that is byte-for-byte larger and no more
 * correct; declaring them once is what every server does and what every client
 * has therefore been tested against.
 *
 * MultiStatusParser on the other side reads with `local-name()` and drops the
 * namespace entirely, because "registering namespace prefixes does not survive
 * contact with real servers". That is a lesson about reading, not writing: a
 * reader must tolerate whatever prefix arrives, and a writer should still emit
 * the conventional ones, since the clients we answer are the ones that were
 * lenient about prefixes but not about the URIs behind them.
 *
 * ── Not-found properties get their own propstat ───────────────────────────
 *
 * A PROPFIND asking for six properties where two are unknown answers with two
 * propstat blocks — 200 for the four, 404 for the two — rather than silently
 * omitting them. Clients use the 404 block to learn what a server does not
 * support and to stop asking; omission reads as "the property exists and is
 * empty", which is how a calendar ends up displaying a blank name forever.
 */
final class MultiStatusBuilder
{
    public const string NS_DAV     = 'DAV:';
    public const string NS_CALDAV  = 'urn:ietf:params:xml:ns:caldav';
    public const string NS_CS      = 'http://calendarserver.org/ns/';
    public const string NS_ICAL    = 'http://apple.com/ns/ical/';

    private XMLWriter $writer;

    public function __construct()
    {
        $this->writer = new XMLWriter();
        $this->writer->openMemory();
        $this->writer->setIndent(true);
        $this->writer->setIndentString('  ');
        $this->writer->startDocument('1.0', 'UTF-8');

        $this->writer->startElementNS('d', 'multistatus', self::NS_DAV);
        $this->writer->writeAttribute('xmlns:c', self::NS_CALDAV);
        $this->writer->writeAttribute('xmlns:cs', self::NS_CS);
        $this->writer->writeAttribute('xmlns:ical', self::NS_ICAL);
    }

    /**
     * One resource's answer.
     *
     * @param array<string,string|true|Closure(XMLWriter):mixed> $found    prefixed
     *        property name ("d:displayname") => text, true for an empty element,
     *        or a closure that writes its own children
     * @param list<string>                                      $notFound prefixed names
     */
    public function response(string $href, array $found, array $notFound = []): self
    {
        $this->writer->startElement('d:response');
        $this->writer->writeElement('d:href', $href);

        if ([] !== $found) {
            $this->writer->startElement('d:propstat');
            $this->writer->startElement('d:prop');

            foreach ($found as $name => $value) {
                $this->property($name, $value);
            }

            $this->writer->endElement();
            $this->writer->writeElement('d:status', 'HTTP/1.1 200 OK');
            $this->writer->endElement();
        }

        if ([] !== $notFound) {
            $this->writer->startElement('d:propstat');
            $this->writer->startElement('d:prop');

            foreach ($notFound as $name) {
                $this->writer->writeElement($name);
            }

            $this->writer->endElement();
            $this->writer->writeElement('d:status', 'HTTP/1.1 404 Not Found');
            $this->writer->endElement();
        }

        $this->writer->endElement();

        return $this;
    }

    /**
     * A response carrying only a status, which is how sync-collection reports a
     * resource that is gone: an href and a 404, with no propstat at all.
     */
    public function status(string $href, string $status): self
    {
        $this->writer->startElement('d:response');
        $this->writer->writeElement('d:href', $href);
        $this->writer->writeElement('d:status', $status);
        $this->writer->endElement();

        return $this;
    }

    /** The sync-token that closes a sync-collection REPORT (RFC 6578 §3.2). */
    public function syncToken(string $token): self
    {
        $this->writer->writeElement('d:sync-token', $token);

        return $this;
    }

    public function toXml(): string
    {
        $this->writer->endElement();
        $this->writer->endDocument();

        return $this->writer->outputMemory();
    }

    /**
     * @param string|true|Closure(XMLWriter):mixed $value
     */
    private function property(string $name, string|bool|Closure $value): void
    {
        if (true === $value) {
            $this->writer->writeElement($name);

            return;
        }

        if ($value instanceof Closure) {
            $this->writer->startElement($name);
            $value($this->writer);
            $this->writer->endElement();

            return;
        }

        $this->writer->writeElement($name, $value);
    }
}
