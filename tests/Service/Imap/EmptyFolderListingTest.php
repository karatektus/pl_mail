<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Entity\Mail\Mailbox;
use App\Service\Imap\ImapFolderListing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\RuntimeException as ImapRuntimeException;
use Webklex\PHPIMAP\IMAP;

/**
 * A folder with nothing in it has a listing: the empty one.
 *
 * `UID FETCH 1:* (FLAGS)` on such a folder is answered OK with no FETCH lines,
 * and the library raises that as a failure (see ImapEmptyAnswer). The listing
 * reported it as the server not answering, so the folder was never swept —
 * mail deleted from it elsewhere stayed in plMail, and an unswept folder
 * withholds reaping from its whole account. It is also the log line #96 quotes
 * for an iCloud Drafts folder the server returns nothing from.
 *
 * Whether an empty listing is BELIEVED is the sweep's business, and it has a
 * guard for a large folder that suddenly lists nothing. This only pins that
 * the answer reaches it.
 */
final class EmptyFolderListingTest extends TestCase
{
    public function testAFolderTheServerReturnsNothingFromIsListedAsEmpty(): void
    {
        $listing = new ImapFolderListing(new NullLogger())->read(
            $this->client(static fn (): Response => Response::empty()->setResult([])),
            $this->mailbox(),
        );

        self::assertNotNull($listing, 'an empty answer is an answer');
        self::assertSame(7, $listing['uidValidity']);
        self::assertSame([], $listing['uids']);
        self::assertSame([], $listing['flags']);
    }

    /** A connection that died mid-command is still "no answer", and nothing is swept on it. */
    public function testAConnectionThatDiedIsStillNoListingAtAll(): void
    {
        $listing = new ImapFolderListing(new NullLogger())->read(
            $this->client(static fn (): never => throw new ImapRuntimeException('empty response')),
            $this->mailbox(),
        );

        self::assertNull($listing);
    }

    public function testAFolderWithMessagesIsListedAsBefore(): void
    {
        $listing = new ImapFolderListing(new NullLogger())->read(
            $this->client(static fn (): Response => Response::empty()->setResult([11 => ['\\Seen'], 12 => []])),
            $this->mailbox(),
        );

        self::assertNotNull($listing);
        self::assertSame([11 => true, 12 => true], $listing['uids']);
        self::assertSame([11 => ['\\Seen'], 12 => []], $listing['flags']);
    }

    private function mailbox(): Mailbox
    {
        $mailbox           = new Mailbox();
        $mailbox->name     = 'Drafts';
        $mailbox->fullPath = 'Drafts';

        return $mailbox;
    }

    /**
     * @param \Closure(): Response $fetch what `UID FETCH 1:* (FLAGS)` answers
     */
    private function client(\Closure $fetch): Client
    {
        $protocol = new class (Config::make(), $fetch) extends ImapProtocol {
            public function __construct(Config $config, private readonly \Closure $answer)
            {
                parent::__construct($config);
            }

            public function examineFolder(string $folder = 'INBOX'): Response
            {
                return Response::empty()->setResult(['uidvalidity' => 7, 'exists' => 1]);
            }

            public function fetch(array|string $items, array|int $from, mixed $to = null, int|string $uid = IMAP::ST_UID): Response
            {
                return ($this->answer)();
            }
        };

        return new class (Config::make(), $protocol) extends Client {
            public function __construct(Config $config, private readonly ProtocolInterface $fake)
            {
                parent::__construct($config);
            }

            public function getConnection(): ProtocolInterface
            {
                return $this->fake;
            }
        };
    }
}
