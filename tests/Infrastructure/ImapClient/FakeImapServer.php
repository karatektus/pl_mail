<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ImapClient;

use App\Entity\Mail\Account;
use App\Infrastructure\ImapClient\ImapConnection;
use App\Infrastructure\ImapClient\ImapCredentials;
use App\Infrastructure\ImapClient\ImapEndpoint;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSecurity;
use App\Infrastructure\ImapClient\ImapSession;

/**
 * An IMAP server that answers whatever it is asked from a table, for tests
 * of the code that runs on the IMAP client.
 *
 * ScriptedImapServer checks one conversation line by line, which is right for
 * the client's own tests. The services above it — folder sync, write-back,
 * the import — send whatever commands their logic arrives at, and what those
 * tests pin is what the service did with the answers. This answers by command
 * instead: LOGIN, CAPABILITY, LIST (from the folders it was given), STATUS,
 * SELECT and EXAMINE (from the folder states it was given) and LOGOUT out of
 * the box, anything else from a handler the test adds, and BAD for the rest.
 * Every command is recorded, so a test can say what was sent.
 *
 * On the loop the IMAP connector uses, as a task, so a service's blocking
 * call to it runs the server while it waits. It takes any number of
 * connections, one after another.
 *
 * Point an account at it with pointAt().
 */
final class FakeImapServer
{
    public const string PASSWORD = 'fake-imap-password';

    public readonly int $port;

    /** @var list<string> every command received, without its tag */
    public array $commands = [];

    /**
     * Folders' contents, for UID SEARCH, UID FETCH and UID STORE: path => UID
     * => the message as the server would send it. Null is a message the
     * server lists in SEARCH and returns nothing for in FETCH, as iCloud does
     * for one deleted and not yet expunged (#96). Changeable between calls.
     *
     * @var array<string, array<int, string|null>>
     */
    public array $messages = [];

    /** @var array<string, array<int, list<string>>> path => UID => flags */
    public array $flags = [];

    /** Hang up instead of answering a UID FETCH, as a dropped connection does. */
    public bool $dropOnFetch = false;

    /** @var list<list<int>> the UIDs each UID FETCH asked for that the folder holds */
    public array $fetched = [];

    /** The folder the last SELECT or EXAMINE opened. */
    private ?string $selected = null;

    /** @var array<string, callable(string): list<string>> by upper-cased command */
    private array $handlers = [];

    /** @var resource */
    private mixed $socket;

    /**
     * @param array<string, list<string>>                       $folders raw path => LIST attributes
     * @param array<string, array{exists?: int, uidValidity?: int, uidNext?: int}> $states path => what SELECT says
     */
    public function __construct(
        private readonly ImapLoop $loop,
        public array $folders = ['INBOX' => []],
        public string $delimiter = '/',
        public array $states = [],
        public string $capabilities = 'IMAP4rev1 IDLE UIDPLUS MOVE LITERAL+',
    ) {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if (false === $socket) {
            throw new \RuntimeException(sprintf('Could not listen: %s', $errorMessage));
        }

        stream_set_blocking($socket, false);

        $this->socket = $socket;
        $this->port   = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);

        $loop->async(fn () => $this->serve());
    }

    /**
     * Answer a command: the handler gets the arguments as sent and returns
     * the lines to answer with, untagged ones first. A last line that is not
     * untagged is the tagged completion (`OK done`, `NO [NONEXISTENT] no`);
     * without one, `OK done` is added.
     *
     * @param callable(string): list<string> $handler
     */
    public function on(string $command, callable $handler): self
    {
        $this->handlers[strtoupper($command)] = $handler;

        return $this;
    }

    /** A session logged in to this server, for code that takes one. */
    public function session(): ImapSession
    {
        $connection = ImapConnection::open($this->loop, new ImapEndpoint('127.0.0.1', $this->port, ImapSecurity::None));
        $connection->authenticate(new ImapCredentials('user', self::PASSWORD));

        return new ImapSession($connection);
    }

    /** Point the account's incoming server here, in the clear, with this server's password. */
    public function pointAt(Account $account): void
    {
        $account->imapHost       = '127.0.0.1';
        $account->imapPort       = $this->port;
        $account->imapEncryption = null;
        $account->username     ??= 'user';
        $account->password       = self::PASSWORD;
        $account->authType       = 'password';
    }

    /**
     * The commands received whose name is this one.
     *
     * @return list<string> each with its arguments
     */
    public function received(string $command): array
    {
        return array_values(array_filter(
            $this->commands,
            static fn (string $line): bool => 0 === stripos($line, $command . ' ') || 0 === strcasecmp($line, $command),
        ));
    }

    public function stop(): void
    {
        if (true === is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    private function serve(): void
    {
        while (true) {
            // stop() closes the socket under the wait, which wakes it.
            if (false === $this->loop->awaitReadable($this->socket, null) || false === is_resource($this->socket)) {
                return;
            }

            $stream = @stream_socket_accept($this->socket, 0);

            if (false === $stream) {
                continue;
            }

            stream_set_blocking($stream, false);

            $this->loop->async(fn () => $this->converse(new ScriptedImapPeer($this->loop, $stream)));
        }
    }

    private function converse(ScriptedImapPeer $peer): void
    {
        $peer->send(sprintf('* OK [CAPABILITY %s] Fake server ready', $this->capabilities));

        try {
            while (true) {
                $line = $peer->line();

                if (1 !== preg_match('~^(\S+) (\S+)(?: (.*))?$~s', $line, $match)) {
                    continue;
                }

                [, $tag, $command] = $match;
                $arguments         = $match[3] ?? '';

                // A literal announced at the end of the line: take it whole,
                // and what follows it, so the command is recorded as sent.
                while (1 === preg_match('~\{(\d+)(\+?)\}$~', $arguments, $literal)) {
                    if ('' === $literal[2]) {
                        $peer->send('+ go ahead');
                    }

                    $arguments = substr($arguments, 0, -strlen($literal[0])) . $peer->bytes((int) $literal[1]) . $peer->line();
                }

                $command = strtoupper($command);

                if ('UID' === $command && 1 === preg_match('~^(\S+)(?: (.*))?$~s', $arguments, $sub)) {
                    $command   = 'UID ' . strtoupper($sub[1]);
                    $arguments = $sub[2] ?? '';
                }

                $this->commands[] = '' === $arguments ? $command : $command . ' ' . $arguments;

                $replies = $this->answer($command, $arguments);

                if (null === $replies) {
                    $peer->close();

                    return;
                }

                foreach ($replies as $index => $reply) {
                    $isLast = $index === array_key_last($replies);

                    if (true === str_starts_with($reply, '*') || true === str_starts_with($reply, '+')) {
                        $peer->send($reply);

                        if (true === $isLast) {
                            $peer->send(sprintf('%s OK done', $tag));
                        }

                        continue;
                    }

                    $peer->send(sprintf('%s %s', $tag, $reply));
                }

                if ('LOGOUT' === $command) {
                    $peer->close();

                    return;
                }
            }
        } catch (\RuntimeException) {
            // The client went away; nothing more to say to it.
        }
    }

    /** @return list<string>|null null to hang up */
    private function answer(string $command, string $arguments): ?array
    {
        $handler = $this->handlers[$command] ?? null;

        if (null !== $handler) {
            $lines = $handler($arguments);

            return [] === $lines ? ['OK done'] : $lines;
        }

        return match ($command) {
            'LOGIN'      => sprintf('"user" "%s"', self::PASSWORD) === preg_replace('~^"[^"]*"~', '"user"', $arguments)
                ? [sprintf('OK [CAPABILITY %s] Logged in', $this->capabilities)]
                : ['NO [AUTHENTICATIONFAILED] Invalid credentials'],
            'CAPABILITY' => [sprintf('* CAPABILITY %s', $this->capabilities)],
            'LOGOUT'     => ['* BYE Logging out'],
            'NOOP'       => ['OK done'],
            'LIST'       => $this->listing(),
            'STATUS'     => $this->status($arguments),
            'SELECT', 'EXAMINE' => $this->select($command, $arguments),
            'UID SEARCH' => $this->search($arguments),
            'UID FETCH'  => true === $this->dropOnFetch ? null : $this->fetch($arguments),
            'UID STORE'  => $this->store($arguments),
            default      => ['BAD unknown command'],
        };
    }

    /** @return list<string> */
    private function listing(): array
    {
        $lines = [];

        foreach ($this->folders as $path => $attributes) {
            $lines[] = sprintf('* LIST (%s) "%s" "%s"', implode(' ', $attributes), $this->delimiter, addcslashes((string) $path, '"\\'));
        }

        return [] === $lines ? ['OK done'] : $lines;
    }

    /** @return list<string> */
    private function select(string $command, string $arguments): array
    {
        $path = self::unquote($arguments);

        if (false === array_key_exists($path, $this->folders)) {
            return ['NO [NONEXISTENT] No such mailbox'];
        }

        $this->selected = $path;
        $held           = $this->messages[$path] ?? [];
        $state          = $this->states[$path] ?? [
            'exists'  => count($held),
            'uidNext' => [] === $held ? 1 : max(array_keys($held)) + 1,
        ];

        return [
            sprintf('* %d EXISTS', $state['exists'] ?? 0),
            sprintf('* OK [UIDVALIDITY %d] UIDs valid', $state['uidValidity'] ?? 1),
            sprintf('* OK [UIDNEXT %d] Predicted next UID', $state['uidNext'] ?? 1),
            'EXAMINE' === $command ? 'OK [READ-ONLY] Examined' : 'OK [READ-WRITE] Selected',
        ];
    }

    /** @return list<string> */
    private function status(string $arguments): array
    {
        if (1 !== preg_match('~^("(?:[^"\\\\]|\\\\.)*"|\S+) \((.*)\)$~', $arguments, $match)) {
            return ['BAD bad STATUS'];
        }

        $path = self::unquote($match[1]);

        if (false === array_key_exists($path, $this->folders)) {
            return ['NO [NONEXISTENT] No such mailbox'];
        }

        $state  = $this->states[$path] ?? [];
        $values = [
            'MESSAGES'    => $state['exists'] ?? 0,
            'UIDVALIDITY' => $state['uidValidity'] ?? 1,
            'UIDNEXT'     => $state['uidNext'] ?? 1,
        ];
        $pairs = [];

        foreach (preg_split('~\s+~', trim($match[2])) ?: [] as $item) {
            if (true === isset($values[strtoupper($item)])) {
                $pairs[] = sprintf('%s %d', strtoupper($item), $values[strtoupper($item)]);
            }
        }

        return [sprintf('* STATUS %s (%s)', $match[1], implode(' ', $pairs))];
    }

    /** @return list<string> */
    private function search(string $arguments): array
    {
        $held = $this->messages[(string) $this->selected] ?? [];
        $uids = array_keys($held);

        if (1 === preg_match('~^UID (\S+)$~i', trim($arguments), $match)) {
            $uids = $this->inSet($match[1], $uids);
        }

        sort($uids);

        return [trim('* SEARCH ' . implode(' ', $uids)), 'OK done'];
    }

    /** @return list<string> */
    private function fetch(string $arguments): array
    {
        if (1 !== preg_match('~^(\S+) \((.*)\)$~s', trim($arguments), $match)) {
            return ['BAD bad FETCH'];
        }

        $path  = (string) $this->selected;
        $held  = $this->messages[$path] ?? [];
        $uids  = $this->inSet($match[1], array_keys($held));
        $body  = true === str_contains(strtoupper($match[2]), 'BODY.PEEK[]');
        $lines = [];

        sort($uids);
        $this->fetched[] = $uids;

        foreach ($uids as $sequence => $uid) {
            $raw = $held[$uid];

            if (null === $raw) {
                continue;
            }

            $items = sprintf('UID %d FLAGS (%s)', $uid, implode(' ', $this->flags[$path][$uid] ?? []));

            if (true === $body) {
                $items .= sprintf(" BODY[] {%d}\r\n%s", strlen($raw), $raw);
            }

            $lines[] = sprintf('* %d FETCH (%s)', $sequence + 1, $items);
        }

        return [...$lines, 'OK done'];
    }

    /** @return list<string> */
    private function store(string $arguments): array
    {
        if (1 !== preg_match('~^(\S+) ([+-]?)FLAGS(?:\.SILENT)? \((.*)\)$~i', trim($arguments), $match)) {
            return ['BAD bad STORE'];
        }

        $path = (string) $this->selected;

        foreach ($this->inSet($match[1], array_keys($this->messages[$path] ?? [])) as $uid) {
            $flags = preg_split('~\s+~', trim($match[3])) ?: [];
            $now   = $this->flags[$path][$uid] ?? [];

            $this->flags[$path][$uid] = array_values(array_filter(array_unique(match ($match[2]) {
                '+'     => [...$now, ...$flags],
                '-'     => array_diff($now, $flags),
                default => $flags,
            })));
        }

        return ['OK done'];
    }

    /**
     * @param list<int> $held
     *
     * @return list<int> the held UIDs the set names
     */
    private function inSet(string $set, array $held): array
    {
        $top    = [] === $held ? 0 : max($held);
        $chosen = [];

        foreach (explode(',', $set) as $part) {
            $ends = array_map(static fn (string $end): int => '*' === $end ? $top : (int) $end, explode(':', $part));
            $low  = min($ends);
            $high = max($ends);

            foreach ($held as $uid) {
                if ($uid >= $low && $uid <= $high) {
                    $chosen[$uid] = true;
                }
            }
        }

        return array_keys($chosen);
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);

        if (true === str_starts_with($value, '"') && true === str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }
}
