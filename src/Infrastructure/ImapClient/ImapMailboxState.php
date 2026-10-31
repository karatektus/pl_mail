<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

/**
 * What SELECT or EXAMINE said about the folder it opened.
 *
 * Null where the server did not say. UIDVALIDITY and UIDNEXT are optional in
 * practice whatever the RFC requires, and every caller that relies on one has
 * to decide what a server that leaves it out deserves.
 */
final readonly class ImapMailboxState
{
    public function __construct(
        public string $path,
        public int    $exists,
        public ?int   $uidValidity,
        public ?int   $uidNext,
        public ?int   $highestModSeq,
        public bool   $readOnly,
        /**
         * The flags the server keeps on this folder's mail, from the
         * PERMANENTFLAGS response code; `\*` among them means it keeps
         * keywords of the client's choosing. Null when it did not say.
         *
         * @var list<string>|null
         */
        public ?array $permanentFlags = null,
    ) {
    }

    public static function fromResult(string $path, ImapResult $result): self
    {
        return new self(
            path:          $path,
            exists:        $result->number('EXISTS') ?? 0,
            uidValidity:   self::integer($result->code('UIDVALIDITY')),
            uidNext:       self::integer($result->code('UIDNEXT')),
            highestModSeq: self::integer($result->code('HIGHESTMODSEQ')),
            readOnly:      'READ-ONLY' === $result->completion->code,
            permanentFlags: self::flags($result->code('PERMANENTFLAGS')),
        );
    }

    /**
     * @param list<mixed>|null $codeData
     *
     * @return list<string>|null
     */
    private static function flags(?array $codeData): ?array
    {
        $list = $codeData[0] ?? null;

        return true === is_array($list) ? array_values(array_filter($list, is_string(...))) : null;
    }

    /** @param list<mixed>|null $codeData */
    private static function integer(?array $codeData): ?int
    {
        $value = $codeData[0] ?? null;

        return true === is_string($value) && 1 === preg_match('~^\d+$~', $value) ? (int) $value : null;
    }
}
