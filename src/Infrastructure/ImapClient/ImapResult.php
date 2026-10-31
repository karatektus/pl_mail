<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

/**
 * What a command brought back: the tagged response that ended it, and every
 * untagged response that arrived while it ran.
 *
 * The untagged ones are not necessarily about the command. A server may tell
 * any command's caller that new mail has arrived, so a caller that wants to
 * know about EXISTS has to look here whatever it asked.
 */
final readonly class ImapResult
{
    /**
     * @param list<ImapResponse> $untagged
     */
    public function __construct(
        public ImapResponse $completion,
        public array        $untagged,
    ) {
    }

    /**
     * The untagged responses with this keyword.
     *
     * @return list<ImapResponse>
     */
    public function untagged(string $keyword): array
    {
        $keyword = strtoupper($keyword);

        return array_values(array_filter(
            $this->untagged,
            static fn (ImapResponse $response): bool => $keyword === $response->keyword,
        ));
    }

    /**
     * The number of the first untagged response with this keyword —
     * `* 23 EXISTS` — or null when the server sent none.
     */
    public function number(string $keyword): ?int
    {
        return $this->untagged($keyword)[0]->number ?? null;
    }

    /**
     * The arguments of the first response code with this name, from the
     * untagged responses or the completion — `[UIDVALIDITY 3857529045]`.
     *
     * @return list<mixed>|null null when no response carried the code
     */
    public function code(string $code): ?array
    {
        $code = strtoupper($code);

        foreach ([...$this->untagged, $this->completion] as $response) {
            if ($code === $response->code) {
                return $response->codeData;
            }
        }

        return null;
    }
}
