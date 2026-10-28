<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;

/**
 * An IMAP server for the folder commands, in memory: it answers NAMESPACE,
 * CREATE, RENAME, DELETE, STATUS and the subscriptions, writes down every
 * command it was sent, and refuses what a test tells it to.
 *
 * @internal for ImapFolderManagerTest
 */
final class FakeFolderServer extends ImapProtocol
{
    /** @var list<string> every command, as "CREATE INBOX.Kine" */
    public array $commands = [];

    /** @var array<string, int> path => MESSAGES, for STATUS */
    public array $messages = [];

    /** @var array<string, bool> path => whether its PERMANENTFLAGS carry `\*` */
    public array $keywordFolders = [];

    /** A command prefix ("CREATE", "RENAME …") and the NO line it gets. */
    public ?string $refuse = null;

    public string $refusal = 'NO [CANNOT] Permission denied';

    public function __construct(
        private readonly string $namespacePrefix = '',
        private readonly string $namespaceSeparator = '/',
    ) {
        parent::__construct(Config::make());
    }

    public function client(): Client
    {
        $server = $this;

        return new class ($server) extends Client {
            public function __construct(private readonly FakeFolderServer $server)
            {
                parent::__construct(Config::make());
            }

            public function getConnection(): ProtocolInterface
            {
                return $this->server;
            }

            public function disconnect(): Client
            {
                return $this;
            }
        };
    }

    public function requestAndResponse(string $command, array $tokens = [], bool $dontParse = false): Response
    {
        if ('NAMESPACE' === $command) {
            return Response::empty()->setResult([
                ['NAMESPACE', [[$this->namespacePrefix, '"' . $this->namespaceSeparator . '"']], 'NIL', 'NIL'],
                ['OK', 'NAMESPACE', 'completed'],
            ]);
        }

        if ('SELECT' === $command) {
            $path = trim((string) ($tokens[0] ?? ''), '"');
            $this->commands[] = 'SELECT ' . $path;
            $flags = ['\\Deleted', '\\Seen', '\\Answered', '\\Flagged', true === ($this->keywordFolders[$path] ?? false) ? '\\Draft' : '\\Draft)]'];

            if (true === ($this->keywordFolders[$path] ?? false)) {
                $flags[] = '\\*)]';
            }

            return Response::empty()->setResult([['OK', '[PERMANENTFLAGS', $flags]]);
        }

        return parent::requestAndResponse($command, $tokens, $dontParse);
    }

    public function store(
        array|string $flags,
        int $from,
        ?int $to = null,
        ?string $mode = null,
        bool $silent = true,
        int|string $uid = \Webklex\PHPIMAP\IMAP::ST_UID,
        ?string $item = null,
    ): Response {
        return $this->answer(sprintf('STORE %d %sFLAGS (%s)', $from, (string) $mode, implode(' ', (array) $flags)));
    }

    public function createFolder(string $folder): Response
    {
        return $this->answer('CREATE ' . $folder);
    }

    public function renameFolder(string $old, string $new): Response
    {
        return $this->answer('RENAME ' . $old . ' ' . $new);
    }

    public function deleteFolder(string $folder): Response
    {
        return $this->answer('DELETE ' . $folder);
    }

    public function subscribeFolder(string $folder): Response
    {
        return $this->answer('SUBSCRIBE ' . $folder);
    }

    public function unsubscribeFolder(string $folder): Response
    {
        return $this->answer('UNSUBSCRIBE ' . $folder);
    }

    public function folderStatus(string $folder = 'INBOX', $arguments = []): Response
    {
        $this->commands[] = 'STATUS ' . $folder;

        return Response::empty()->setResult(['messages' => $this->messages[$folder] ?? 0]);
    }

    private function answer(string $command): Response
    {
        if (null !== $this->refuse && str_starts_with($command, $this->refuse)) {
            throw new ImapServerErrorException($this->refusal);
        }

        $this->commands[] = $command;

        return Response::empty()->setResult([true]);
    }
}
