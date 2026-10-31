<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\Exception\ImapCommandException;
use App\Infrastructure\ImapClient\Exception\ImapProtocolException;
use App\Infrastructure\ImapClient\Exception\ImapTimeoutException;

/**
 * An IDLE in progress (RFC 2177): the server tells this connection about
 * changes to the selected folder as they happen, until done().
 *
 * wait() hands over what the server said, as untagged responses, and returns
 * at once when there is something rather than at the end of its time, so the
 * caller can act on a new message while it is new. Nothing here interprets
 * what was said: EXISTS, EXPUNGE, FETCH and an ALERT mean different things to
 * different callers.
 *
 * The connection can do nothing else meanwhile. RFC 2177 asks a client to end
 * an IDLE within 29 minutes, since a server may drop one that runs longer;
 * keeping that time is the caller's, because only the caller knows what else
 * it wants the connection for.
 */
final class ImapIdle
{
    /** Whether the server has ended the IDLE itself, with its tagged response. */
    public private(set) bool $hasEnded = false;

    /**
     * @param list<ImapResponse> $pending untagged responses that came before the server's go-ahead
     */
    public function __construct(
        private readonly ImapConnection $connection,
        private readonly string $tag,
        private array $pending,
    ) {
    }

    /**
     * What the server says within $seconds.
     *
     * @return list<ImapResponse> empty when it said nothing in that time, or
     *                            the server has ended the IDLE
     */
    public function wait(float $seconds): array
    {
        if (true === $this->hasEnded) {
            return [];
        }

        $responses     = $this->pending;
        $this->pending = [];

        if ([] === $responses) {
            $first = $this->connection->readResponse(microtime(true) + $seconds);

            if (null === $first) {
                return [];
            }

            $responses[] = $first;
        }

        // Whatever else came in the same read is here already: hand it over
        // together, rather than one wake-up per line of a burst.
        while (null !== ($next = $this->connection->bufferedResponse())) {
            $responses[] = $next;
        }

        return $this->untagged($responses);
    }

    /**
     * End the IDLE and wait for the server to agree.
     *
     * @return list<ImapResponse> anything the server said on the way out
     */
    public function done(): array
    {
        if (true === $this->hasEnded) {
            return [];
        }

        $deadline  = microtime(true) + $this->connection->timeoutSeconds;
        $responses = $this->pending;

        $this->pending = [];
        $this->connection->write("DONE\r\n", $deadline);

        while (true) {
            $response = $this->connection->readResponse($deadline);

            if (null === $response) {
                $this->connection->close();

                throw new ImapTimeoutException(sprintf(
                    '%s did not end IDLE in time.',
                    $this->connection->endpoint->host,
                ));
            }

            $responses = [...$responses, ...$this->untagged([$response])];

            if (true === $response->completes($this->tag)) {
                return $responses;
            }
        }
    }

    /**
     * Keep the untagged responses, and notice the one that ends the IDLE.
     *
     * @param list<ImapResponse> $responses
     *
     * @return list<ImapResponse>
     */
    private function untagged(array $responses): array
    {
        $kept = [];

        foreach ($responses as $response) {
            if (true === $response->completes($this->tag)) {
                $this->hasEnded = true;
                $this->connection->release();

                if ('OK' !== $response->status) {
                    throw new ImapCommandException('IDLE', $response);
                }

                continue;
            }

            if (ImapResponseKind::Untagged !== $response->kind) {
                $this->connection->close();

                throw new ImapProtocolException(sprintf(
                    '%s sent %s during IDLE.',
                    $this->connection->endpoint->host,
                    trim($response->raw),
                ));
            }

            $kept[] = $response;
        }

        return $kept;
    }
}
