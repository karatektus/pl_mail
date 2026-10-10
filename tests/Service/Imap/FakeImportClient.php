<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * A server with one folder in it, whose contents are a script.
 *
 * For ImapMailboxImporter, which asks a folder two things — which UIDs it
 * holds below a floor, and for a named set of them — and for nothing else.
 * Both go through Folder::messages(), so that is the one seam: the folder here
 * hands out a FakeQuery over the script, and everything above it is the real
 * importer, the real paged fetch and the real MessageSyncer.
 *
 * The script can be changed between calls (`$script` is public), which is how
 * a test says "and now the message that would not parse parses".
 */
final class FakeImportClient extends Client
{
    /** @var array<int, Message|\Throwable> UID => the message, or what building it throws */
    public array $script = [];

    /** @var list<list<int>> the UIDs asked for by name, one entry per page fetched */
    public array $fetched = [];

    /**
     * @param array<int, Message|\Throwable> $script
     */
    public static function holding(array $script): self
    {
        $client = new \ReflectionClass(self::class)->newInstanceWithoutConstructor();
        $client->script = $script;

        return $client;
    }

    public function getFolderByPath($folder_path, bool $utf7 = false, bool $soft_fail = false): Folder
    {
        return $this->folder();
    }

    public function getFolderByName($folder_name, bool $soft_fail = false): Folder
    {
        return $this->folder();
    }

    public function disconnect(): Client
    {
        return $this;
    }

    private function folder(): Folder
    {
        $client = $this;

        return new class($client) extends Folder {
            public function __construct(private readonly FakeImportClient $server)
            {
            }

            public function messages(array $extensions = []): WhereQuery
            {
                return $this->server->query();
            }
        };
    }

    public function query(): WhereQuery
    {
        return FakeQuery::finding($this->script, function (array $uids): void {
            $this->fetched[] = $uids;
        });
    }
}
