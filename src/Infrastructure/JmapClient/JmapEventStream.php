<?php

declare(strict_types=1);

namespace App\Infrastructure\JmapClient;

/**
 * Reads a JMAP EventSource response (RFC 8620 §7.3) as it arrives.
 *
 * The server-sent-events framing and nothing else: fields per line, events
 * separated by a blank line, `data:` lines joined with a newline, `:`
 * comments ignored. Kept apart from the listener so it can be fed chunks cut
 * at any byte — a chunk boundary falls mid-line as often as not, and an event
 * read before its blank line would be an event read half.
 *
 * One per stream: it holds what arrived after the last complete event.
 */
final class JmapEventStream
{
    private string $buffer = '';

    /**
     * Take a chunk and hand back the events it completed.
     *
     * @return list<array{event: string, data: string}>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= str_replace(["\r\n", "\r"], "\n", $chunk);
        $events        = [];

        while (false !== ($end = strpos($this->buffer, "\n\n"))) {
            $block        = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 2);

            $event = 'message';
            $data  = [];

            foreach (explode("\n", $block) as $line) {
                if ('' === $line || str_starts_with($line, ':')) {
                    continue;
                }

                [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
                $value           = str_starts_with($value, ' ') ? substr($value, 1) : $value;

                match ($field) {
                    'event' => $event = $value,
                    'data'  => $data[] = $value,
                    default => null,
                };
            }

            if ([] !== $data) {
                $events[] = ['event' => $event, 'data' => implode("\n", $data)];
            }
        }

        return $events;
    }

    /**
     * The StateChange objects among some events, decoded.
     *
     * RFC 8620 names the event `state`; a ping is `ping` and carries nothing
     * a sync needs.
     *
     * @param list<array{event: string, data: string}> $events
     *
     * @return list<array<string, mixed>>
     */
    public static function stateChanges(array $events): array
    {
        $changes = [];

        foreach ($events as $event) {
            if ('state' !== $event['event']) {
                continue;
            }

            $decoded = json_decode($event['data'], true);

            if (true === is_array($decoded) && true === is_array($decoded['changed'] ?? null)) {
                $changes[] = $decoded;
            }
        }

        return $changes;
    }
}
