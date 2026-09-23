<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/**
 * @internal the protocol half of FakeListingClient
 */
final class FakeListingProtocol extends ImapProtocol
{
    /** @var array<string, array{delimiter: string, flags: list<string>}> */
    public array $items = [];

    public function folders(string $reference = '', string $folder = '*'): Response
    {
        return Response::empty()->setResult($this->items);
    }

    public function folderStatus(string $folder = 'INBOX', $arguments = []): Response
    {
        return Response::empty()->setResult([]);
    }
}
