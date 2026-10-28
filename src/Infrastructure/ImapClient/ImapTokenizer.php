<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\Exception\ImapProtocolException;

/**
 * Reads IMAP data — atoms, quoted strings, literals, NIL and parenthesised
 * lists — out of one complete response.
 *
 * The input is a whole response as ImapResponseReader framed it, literals and
 * all: `{5}\r\nhello` is read as the five bytes that follow the announcement,
 * so a literal may contain anything, brackets and line breaks included.
 *
 * Atoms are read the way servers write fetch items rather than the way the
 * grammar names them: `BODY[HEADER.FIELDS (FROM DATE)]<0>` is one token,
 * because the bracket is part of the item name and the space and parentheses
 * inside it do not end it.
 */
final class ImapTokenizer
{
    private int $position;

    private function __construct(
        private readonly string $input,
        int $offset,
    ) {
        $this->position = $offset;
    }

    /**
     * Read tokens from $offset to the end of the response.
     *
     * @return list<mixed>
     */
    public static function tokenize(string $input, int $offset = 0): array
    {
        $tokenizer = new self($input, $offset);

        return $tokenizer->sequence(null);
    }

    /**
     * Read the arguments of a response code: from just after `[CODE` to the
     * closing bracket.
     *
     * @return array{0: list<mixed>, 1: int} the tokens, and where the bracket was
     */
    public static function codeArguments(string $input, int $offset): array
    {
        $tokenizer = new self($input, $offset);
        $tokens    = $tokenizer->sequence(']');

        return [$tokens, $tokenizer->position];
    }

    /**
     * @param string|null $closer the character that ends this sequence, or null for the end of the line
     *
     * @return list<mixed>
     */
    private function sequence(?string $closer): array
    {
        $tokens = [];
        $length = strlen($this->input);

        while ($this->position < $length) {
            $char = $this->input[$this->position];

            if (' ' === $char) {
                ++$this->position;

                continue;
            }

            if ("\r" === $char || "\n" === $char) {
                if (null !== $closer) {
                    throw new ImapProtocolException(sprintf('Unterminated "%s" in: %s', '(' === $closer ? ')' : $closer, rtrim($this->input)));
                }

                return $tokens;
            }

            if (null !== $closer && $closer === $char) {
                ++$this->position;

                return $tokens;
            }

            $tokens[] = match ($char) {
                '('     => $this->listToken(),
                '"'     => $this->quoted(),
                '{'     => $this->literal(),
                default => $this->atom(']' === $closer),
            };
        }

        if (null !== $closer) {
            throw new ImapProtocolException(sprintf('Unterminated "%s" in: %s', $closer, rtrim($this->input)));
        }

        return $tokens;
    }

    /** @return list<mixed> */
    private function listToken(): array
    {
        ++$this->position;

        return $this->sequence(')');
    }

    private function quoted(): string
    {
        $value  = '';
        $length = strlen($this->input);

        for ($i = $this->position + 1; $i < $length; ++$i) {
            $char = $this->input[$i];

            if ('\\' === $char && $i + 1 < $length) {
                $value .= $this->input[++$i];

                continue;
            }

            if ('"' === $char) {
                $this->position = $i + 1;

                return $value;
            }

            $value .= $char;
        }

        throw new ImapProtocolException(sprintf('Unterminated quoted string in: %s', rtrim($this->input)));
    }

    private function literal(): string
    {
        if (1 !== preg_match('~\G\{(\d+)\+?\}\r?\n~', $this->input, $match, 0, $this->position)) {
            return $this->atom(false);
        }

        $size  = (int) $match[1];
        $start = $this->position + strlen($match[0]);

        if ($start + $size > strlen($this->input)) {
            throw new ImapProtocolException('A literal runs past the end of its response.');
        }

        $this->position = $start + $size;

        return substr($this->input, $start, $size);
    }

    private function atom(bool $inCode): ?string
    {
        $start   = $this->position;
        $length  = strlen($this->input);
        $bracket = 0;

        while ($this->position < $length) {
            $char = $this->input[$this->position];

            if ('[' === $char) {
                ++$bracket;
            } elseif (']' === $char) {
                if (0 === $bracket && true === $inCode) {
                    break;
                }

                $bracket = max(0, $bracket - 1);
            } elseif (0 === $bracket && (' ' === $char || '(' === $char || ')' === $char || "\r" === $char || "\n" === $char)) {
                break;
            } elseif ("\r" === $char || "\n" === $char) {
                break;
            }

            ++$this->position;
        }

        $atom = substr($this->input, $start, $this->position - $start);

        if ('' === $atom) {
            // A character that starts no token, such as a stray ")": step over
            // it, or the caller would ask about the same position for ever.
            ++$this->position;

            return $this->input[$start];
        }

        return 0 === strcasecmp('NIL', $atom) ? null : $atom;
    }
}
