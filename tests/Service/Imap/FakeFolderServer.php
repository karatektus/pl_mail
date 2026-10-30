<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Entity\Mail\Account;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Tests\Infrastructure\ImapClient\FakeImapServer;

/**
 * An IMAP server for the folder actions of #221: what plMail asked it to
 * create, rename, delete and subscribe, in order, and a way to make it refuse.
 *
 * A FakeImapServer underneath, so the folder actions run through the same
 * ImapConnector and ImapSession they use against a real server. This adds
 * the commands they send that it does not answer by itself — NAMESPACE with a
 * personal namespace of the test's choosing, the folder commands, and a
 * SELECT that says whether the folder keeps keywords, with the STORE that
 * writes them (#221 step 2) — and
 * keeps the folder commands as a person would read them: `CREATE INBOX.Kine`,
 * the path unquoted, without the tag.
 */
final class FakeFolderServer
{
    /** @var list<string> the folder commands received, STATUS among them, in order */
    public array $commands = [];

    /** @var array<string, int> raw path => what STATUS says it holds */
    public array $messages = [];

    /** @var array<string, bool> raw path => whether its PERMANENTFLAGS carry `\*` */
    public array $keywordFolders = [];

    /** A command name to refuse, with $refusal. */
    public ?string $refuse = null;

    public string $refusal = 'NO [CANNOT] Permission denied';

    public readonly FakeImapServer $server;

    /**
     * @param array<string, list<string>> $folders raw path => LIST attributes
     */
    public function __construct(
        ImapLoop $loop,
        string   $namespacePrefix = '',
        string   $namespaceSeparator = '/',
        array    $folders = ['INBOX' => []],
    ) {
        $this->server = new FakeImapServer(
            $loop,
            $folders,
            $namespaceSeparator,
            capabilities: 'IMAP4rev1 IDLE UIDPLUS MOVE LITERAL+ NAMESPACE',
        );

        foreach (['CREATE', 'RENAME', 'DELETE', 'SUBSCRIBE', 'UNSUBSCRIBE'] as $verb) {
            $this->server->on($verb, function (string $arguments) use ($verb): array {
                $this->commands[] = $verb . ' ' . implode(' ', self::strings($arguments));

                return null !== $this->refuse && $verb === strtoupper($this->refuse)
                    ? [$this->refusal]
                    : ['OK ' . $verb . ' completed'];
            });
        }

        $this->server->on('NAMESPACE', static fn (): array => [
            sprintf('* NAMESPACE (("%s" "%s")) NIL NIL', $namespacePrefix, $namespaceSeparator),
            'OK NAMESPACE completed',
        ]);

        // SELECT answers with PERMANENTFLAGS, `\*` among them for a folder in
        // $keywordFolders: what the keyword path asks before it writes.
        $this->server->on('SELECT', function (string $arguments): array {
            $path = self::strings($arguments)[0] ?? '';

            $this->commands[] = 'SELECT ' . $path;

            $flags = '\\Deleted \\Seen \\Answered \\Flagged \\Draft' . (true === ($this->keywordFolders[$path] ?? false) ? ' \\*' : '');

            return [
                '* 0 EXISTS',
                '* OK [UIDVALIDITY 1] UIDs valid',
                sprintf('* OK [PERMANENTFLAGS (%s)] Flags permitted', $flags),
                'OK [READ-WRITE] SELECT completed',
            ];
        });

        // Recorded as `STORE 17 +FLAGS (Follow&ACA-up)`, the silent suffix left off.
        $this->server->on('UID STORE', function (string $arguments): array {
            $this->commands[] = 'STORE ' . str_replace('FLAGS.SILENT', 'FLAGS', $arguments);

            return null !== $this->refuse && 'STORE' === strtoupper($this->refuse)
                ? [$this->refusal]
                : ['OK STORE completed'];
        });

        $this->server->on('STATUS', function (string $arguments): array {
            $path = self::strings($arguments)[0] ?? '';

            $this->commands[] = 'STATUS ' . $path;

            return [
                sprintf('* STATUS "%s" (MESSAGES %d)', $path, $this->messages[$path] ?? 0),
                'OK STATUS completed',
            ];
        });
    }

    public function pointAt(Account $account): void
    {
        $this->server->pointAt($account);
    }

    public function stop(): void
    {
        $this->server->stop();
    }

    /**
     * The quoted strings and atoms of a command's arguments, as values.
     *
     * @return list<string>
     */
    private static function strings(string $arguments): array
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|(\\S+)/', $arguments, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $match): string => '' !== ($match[1] ?? '') || '"' === ($match[0][0] ?? '')
                ? stripslashes($match[1])
                : $match[2],
            $matches,
        );
    }
}
