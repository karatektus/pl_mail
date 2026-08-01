<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use Dom\HTMLDocument;
use Psr\Log\LoggerInterface;

/**
 * Pulls the JSON-LD out of a mail body and flattens it to a list of nodes.
 *
 * Sixty lines rather than a dependency. The two candidate packages are a
 * full JSON-LD processor — expansion, framing, remote @context resolution — and
 * that is the wrong tool twice over: none of it is needed to read a
 * FlightReservation, and resolving a remote context means a mail body deciding
 * that plMail should make an HTTP request. This does the one thing that is
 * actually required, which is to find the script blocks and decode them.
 *
 * PHP 8.4's Dom\HTMLDocument rather than DOMDocument, because mail bodies are
 * neither well-formed nor reliably declared: the old parser needs an encoding
 * hack to keep UTF-8 intact and treats real-world mail HTML as a stream of
 * warnings, while the new one is a spec HTML5 parser that takes the encoding as
 * an argument. It also gets comments right, which a regex over the body does
 * not — markup inside <!-- --> is not markup, and a quoted reply is full of it.
 *
 * Everything here is defensive. The input is HTML written by whoever sent the
 * mail, so a body is allowed to be malformed, enormous, or deliberately hostile,
 * and the worst it may cost is the events it contained.
 */
final readonly class JsonLdReader
{
    /**
     * A body with more blocks than this is not a booking confirmation. Both
     * caps exist so that a sender cannot turn one message into an unbounded
     * amount of work for the ingest worker.
     */
    private const int MAX_BLOCKS = 32;

    private const int MAX_NODES = 100;

    /** Past this, a script block is a payload rather than markup. */
    private const int MAX_BLOCK_BYTES = 512_000;

    /**
     * json_decode defaults to 512 levels. Nothing schema.org emits is anywhere
     * near that, and the limit is what bounds the recursion below.
     */
    private const int MAX_DEPTH = 32;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Every typed node in the body, in document order.
     *
     * @return list<Node>
     */
    public function read(string $html): array
    {
        $nodes = [];

        foreach ($this->blocks($html) as $json) {
            $decoded = $this->decode($json);

            if (null === $decoded) {
                continue;
            }

            $this->collect($decoded, 0, $nodes);

            if (self::MAX_NODES <= count($nodes)) {
                break;
            }
        }

        return array_slice($nodes, 0, self::MAX_NODES);
    }

    /**
     * The contents of every <script type="application/ld+json"> element.
     *
     * The type is matched by prefix because a charset parameter is legal and
     * some senders include one.
     *
     * @return list<string>
     */
    private function blocks(string $html): array
    {
        if ('' === trim($html)) {
            return [];
        }

        try {
            // LIBXML_NOERROR alone. The new parser rejects the rest of the
            // libxml constants with a ValueError rather than ignoring them —
            // LIBXML_NOWARNING included, which is the obvious thing to reach
            // for and silently costs every event in the message.
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        } catch (\Throwable $e) {
            // Should not happen — the HTML5 parser has no fatal input — but a
            // body that defeats it costs its events, never the message.
            $this->logger->info('JsonLdReader: body could not be parsed as HTML', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $blocks = [];

        foreach ($document->getElementsByTagName('script') as $script) {
            $type = mb_strtolower(trim((string) $script->getAttribute('type')));

            if (false === str_starts_with($type, 'application/ld+json')) {
                continue;
            }

            $json = trim($script->textContent);

            if ('' === $json || self::MAX_BLOCK_BYTES < strlen($json)) {
                continue;
            }

            $blocks[] = $json;

            if (self::MAX_BLOCKS <= count($blocks)) {
                break;
            }
        }

        return $blocks;
    }

    private function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Routine rather than exceptional: senders emit trailing commas,
            // unescaped newlines and templating that never got substituted.
            $this->logger->info('JsonLdReader: unreadable JSON-LD block', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Flatten the three shapes a block arrives in.
     *
     * A single object, a top-level array of objects, and a @graph wrapper are
     * all in use by senders for the same content, and a message with two legs
     * of a flight will use whichever its template happened to produce. Nesting
     * below that is a mapper's business, not this one's: reservationFor is part
     * of its reservation and must not become a node of its own.
     *
     * @param list<Node> $nodes
     */
    private function collect(mixed $value, int $depth, array &$nodes): void
    {
        if (self::MAX_DEPTH < $depth || self::MAX_NODES <= count($nodes)) {
            return;
        }

        if (false === is_array($value)) {
            return;
        }

        if (true === array_is_list($value)) {
            foreach ($value as $entry) {
                $this->collect($entry, $depth + 1, $nodes);
            }

            return;
        }

        // A node may carry both, and one sender does: an EmailMessage wrapper
        // with the reservations underneath it in a graph.
        if (true === isset($value['@graph'])) {
            $this->collect($value['@graph'], $depth + 1, $nodes);
        }

        if (true === isset($value['@type'])) {
            $nodes[] = new Node($value);
        }
    }
}
