<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

/**
 * One complete response from the server, read.
 *
 * Every shape RFC 9051 allows comes out as the same object, with the parts
 * that do not apply left empty:
 *
 *     A3 OK [READ-WRITE] SELECT completed   tag A3, status OK, code READ-WRITE, text
 *     * OK [ALERT] Mailbox is full           status OK, code ALERT, text
 *     * 23 EXISTS                            number 23, keyword EXISTS
 *     * 4 FETCH (UID 9 FLAGS (\Seen))        number 4, keyword FETCH, data [[UID, 9, FLAGS, [\Seen]]]
 *     * CAPABILITY IMAP4rev1 IDLE            keyword CAPABILITY, data [IMAP4rev1, IDLE]
 *     + idling                               a continuation, text "idling"
 *
 * Data is tokens: a string for an atom, a quoted string or a literal, null for
 * NIL, a list for a parenthesised list. Atoms keep their spelling; keywords,
 * statuses and codes are upper-cased, since the protocol does not care about
 * their case and every caller would otherwise have to.
 */
final readonly class ImapResponse
{
    /** The status words a response can begin with. */
    public const array STATUSES = ['OK', 'NO', 'BAD', 'PREAUTH', 'BYE'];

    /**
     * @param list<mixed> $codeData the arguments of the response code, as tokens
     * @param list<mixed> $data     everything after the keyword, as tokens
     */
    public function __construct(
        public ImapResponseKind $kind,
        public ?string          $tag,
        public ?string          $status,
        public ?int             $number,
        public string           $keyword,
        public ?string          $code,
        public array            $codeData,
        public array            $data,
        public string           $text,
        public string           $raw,
    ) {
    }

    /** Whether this ends the command with the given tag. */
    public function completes(string $tag): bool
    {
        return ImapResponseKind::Tagged === $this->kind && $tag === $this->tag;
    }

    /**
     * The data items of a FETCH response, by upper-cased name.
     *
     * `* 4 FETCH (UID 9 FLAGS (\Seen))` gives ['UID' => '9', 'FLAGS' => ['\Seen']].
     * Empty for anything that is not a FETCH, or a FETCH without its list.
     *
     * @return array<string, mixed>
     */
    public function fetchItems(): array
    {
        if ('FETCH' !== $this->keyword || false === is_array($this->data[0] ?? null)) {
            return [];
        }

        $items = [];
        $list  = $this->data[0];

        for ($i = 0; $i + 1 < count($list); $i += 2) {
            if (true === is_string($list[$i])) {
                $items[strtoupper($list[$i])] = $list[$i + 1];
            }
        }

        return $items;
    }
}
