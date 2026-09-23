<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;

/**
 * A client whose LIST answers from a fixture and which never opens a socket.
 *
 * The fake sits at the protocol, not at Client::getFolders(), because that is
 * where MailboxSyncer reads the listing: it needs the attributes each LIST line
 * carried, which getFolders() throws away. Folder itself stays the real one,
 * so path decoding under test is the library's own.
 */
final class FakeListingClient extends Client
{
    private FakeListingProtocol $fakeProtocol;

    /**
     * @param array<string, list<string>> $folders raw path => LIST attributes
     */
    public function __construct(array $folders, string $delimiter = '.')
    {
        parent::__construct(Config::make());

        $this->fakeProtocol = new FakeListingProtocol(Config::make());

        foreach ($folders as $path => $attributes) {
            $this->fakeProtocol->items[$path] = ['delimiter' => $delimiter, 'flags' => $attributes];
        }
    }

    public function getConnection(): ProtocolInterface
    {
        return $this->fakeProtocol;
    }

    /**
     * Never opens a socket: Client::getFolders() reads `$this->connection`
     * directly, so the fake protocol is put there instead of dialled.
     */
    public function checkConnection(): bool
    {
        $this->connection = $this->fakeProtocol;

        return false;
    }

    /** Never connected, so there is nothing to log out of. */
    public function disconnect(): Client
    {
        return $this;
    }
}
