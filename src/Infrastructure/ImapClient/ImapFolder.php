<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

/**
 * One folder as LIST reports it.
 *
 * The path is kept exactly as the server spelled it — modified UTF-7, the
 * form every command that names the folder needs — and the names are decoded
 * for people. That split is the one webklex made, and Mailbox rows store both
 * halves of it (`fullPath` and `name`), so the decoding below is the
 * library's, segment by segment, to keep every stored name the same.
 */
final readonly class ImapFolder
{
    /**
     * @param list<string> $attributes as the server sent them: `\HasNoChildren`, `\Sent`
     */
    public function __construct(
        public string $path,
        public string $delimiter,
        public array  $attributes,
    ) {
    }

    /**
     * Read one `* LIST` response. A folder without a hierarchy delimiter
     * (`NIL`) gets ".", the default webklex gave it, since a Mailbox row
     * always has one.
     */
    public static function fromList(ImapResponse $response): ?self
    {
        [$attributes, $delimiter, $path] = $response->data + [null, null, null];

        if (false === is_string($path)) {
            return null;
        }

        return new self(
            $path,
            true === is_string($delimiter) && '' !== $delimiter ? $delimiter : '.',
            array_values(array_filter(is_array($attributes) ? $attributes : [], is_string(...))),
        );
    }

    /** The whole path in UTF-8: `Archiv/Entwürfe`. */
    public function fullName(): string
    {
        return implode($this->delimiter, array_map(
            static function (string $segment): string {
                $decoded = mb_convert_encoding($segment, 'UTF-8', 'UTF7-IMAP');

                return false === $decoded ? $segment : $decoded;
            },
            explode($this->delimiter, $this->path),
        ));
    }

    /** The last segment of the decoded path: `Entwürfe`. */
    public function name(): string
    {
        $segments = explode($this->delimiter, $this->fullName());

        return (string) end($segments);
    }

    /** Whether the folder has this attribute, compared the way RFC 9051 says: without case. */
    public function hasAttribute(string $attribute): bool
    {
        foreach ($this->attributes as $candidate) {
            if (0 === strcasecmp($candidate, $attribute)) {
                return true;
            }
        }

        return false;
    }

    /** `\Noselect`, or `\NonExistent`, which implies it (RFC 5258). */
    public function isSelectable(): bool
    {
        return false === $this->hasAttribute('\\Noselect') && false === $this->hasAttribute('\\NonExistent');
    }
}
