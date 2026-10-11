<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Entity\Mail\Mailbox;
use App\Service\Imap\ImapImportPlan;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/**
 * The first time a folder is met, the mark for new mail goes to its top and
 * its history is left for the importer.
 *
 * That one move is what makes a new account show today's mail first. Without
 * it the mark starts at nothing and the first sync asks for `1:*` — the whole
 * folder, oldest first, in the job that was only meant to look (#42).
 */
final class ImapImportPlanTest extends TestCase
{
    public function testAFolderIsPlannedFromItsTopWithEverythingBelowLeftAsHistory(): void
    {
        $mailbox = $this->mailbox();

        self::assertTrue($this->plan()->begin($mailbox, $this->serverSaying(['uidnext' => 4712])));

        self::assertSame(4711, $mailbox->lastSeenUid, 'new mail is whatever comes after the newest message there is');
        self::assertSame(4712, $mailbox->importFloorUid, 'and all of what is there is history');
        self::assertTrue($mailbox->isImporting());
    }

    /** No history is no import, and saying so keeps the folder off the importer's list. */
    public function testAnEmptyFolderHasNothingToImport(): void
    {
        $mailbox = $this->mailbox();

        $this->plan()->begin($mailbox, $this->serverSaying(['uidnext' => 1]));

        self::assertSame(0, $mailbox->importFloorUid);
        self::assertFalse($mailbox->isImporting());
    }

    /**
     * A folder the old oldest-first pass left half read has a mark in its
     * middle. It goes up to the top, never down, and the gap is history.
     */
    public function testAMarkLeftInTheMiddleOfAFolderOnlyEverMovesUp(): void
    {
        $mailbox              = $this->mailbox();
        $mailbox->lastSeenUid = 900;

        $this->plan()->begin($mailbox, $this->serverSaying(['uidnext' => 4712]));

        self::assertSame(4711, $mailbox->lastSeenUid);

        $ahead              = $this->mailbox();
        $ahead->lastSeenUid = 9000;

        $this->plan()->begin($ahead, $this->serverSaying(['uidnext' => 4712]));

        self::assertSame(9000, $ahead->lastSeenUid);
    }

    /**
     * A server that will not say where its top is has not given enough to
     * plan with. The folder stays unplanned and is read the old way, which is
     * slow and correct — the alternative is guessing which mail is new.
     */
    public function testAServerThatDoesNotNameItsNextUidLeavesTheFolderUnplanned(): void
    {
        $mailbox = $this->mailbox();

        self::assertFalse($this->plan()->begin($mailbox, $this->serverSaying(['uidvalidity' => 7])));

        self::assertNull($mailbox->importFloorUid);
        self::assertNull($mailbox->lastSeenUid);
    }

    private function plan(): ImapImportPlan
    {
        return new ImapImportPlan($this->createStub(EntityManagerInterface::class), new NullLogger());
    }

    private function mailbox(): Mailbox
    {
        $mailbox           = new Mailbox();
        $mailbox->name     = 'INBOX';
        $mailbox->fullPath = 'INBOX';

        return $mailbox;
    }

    /**
     * @param array<string, int> $examined what the server answers an EXAMINE with
     *
     * A subclass rather than a stub of Client: the library's client disconnects
     * in its destructor, and a stub answers that call with another stub of
     * itself, which is then destroyed in turn — without end.
     */
    private function serverSaying(array $examined): Client
    {
        $response = $this->createStub(Response::class);
        $response->method('validatedData')->willReturn($examined);

        $connection = $this->createStub(ProtocolInterface::class);
        $connection->method('examineFolder')->willReturn($response);

        $client = new \ReflectionClass(ExaminedClient::class)->newInstanceWithoutConstructor();
        $client->server = $connection;

        return $client;
    }
}

/** A client that is connected to whatever it is handed and never hangs up. */
final class ExaminedClient extends Client
{
    public ProtocolInterface $server;

    public function getConnection(): ProtocolInterface
    {
        return $this->server;
    }

    public function disconnect(): Client
    {
        return $this;
    }
}
