<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

/**
 * One argument of a command, and how it goes on the wire.
 *
 * The constructors say what the value is, and the encoding follows from
 * that: an atom is written as it is, a string as a quoted string when it can
 * be one and as a literal when it cannot, a list in parentheses. Nothing that
 * came from outside is written as an atom — a folder name or a password with
 * a space or a quote in it would otherwise end the argument early, or start a
 * new command.
 */
final readonly class ImapArgument
{
    /**
     * @param list<self> $items the members, for a list; empty otherwise
     */
    private function __construct(
        private string $kind,
        private string $text,
        private array  $items = [],
    ) {
    }

    /**
     * Written exactly as given: command keywords, sequence sets, flags, fetch
     * items. Never for a value from outside.
     */
    public static function atom(string $value): self
    {
        if (1 === preg_match('~[\x00-\x1f\x7f]~', $value)) {
            throw new \InvalidArgumentException('An atom cannot hold control characters.');
        }

        return new self('atom', $value);
    }

    /** Any text: a folder name, a username, a password, a search term. */
    public static function string(#[\SensitiveParameter] string $value): self
    {
        return new self('string', $value);
    }

    /** @param list<self> $items */
    public static function list(array $items): self
    {
        return new self('list', '', $items);
    }

    /**
     * Append this argument to the command being built.
     *
     * A literal splits the command: the line up to `{n}` goes out, the server
     * answers `+` when it will take the n bytes, and the command continues
     * after them. With LITERAL+ (`{n+}`) the server promises to take the
     * bytes without being asked, and the command stays in one piece.
     */
    public function appendTo(ImapCommandBuffer $command, bool $literalPlus): void
    {
        if ('atom' === $this->kind) {
            $command->append($this->text);

            return;
        }

        if ('list' === $this->kind) {
            $command->append('(');

            foreach ($this->items as $index => $item) {
                if (0 < $index) {
                    $command->append(' ');
                }

                $item->appendTo($command, $literalPlus);
            }

            $command->append(')');

            return;
        }

        // Quoted strings cannot carry a line break or a NUL, and RFC 9051
        // keeps them to seven bits unless UTF8=ACCEPT is enabled; anything
        // else goes as a literal, which carries any byte.
        if (1 !== preg_match('~[\x00\r\n\x80-\xff]~', $this->text)) {
            $command->append('"' . addcslashes($this->text, '"\\') . '"');

            return;
        }

        if (true === $literalPlus) {
            $command->append(sprintf("{%d+}\r\n", strlen($this->text)) . $this->text);

            return;
        }

        $command->append(sprintf("{%d}\r\n", strlen($this->text)));
        $command->waitForServer();
        $command->append($this->text);
    }
}
