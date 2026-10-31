<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Entity\Mail\Mailbox;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSession;
use App\Service\Imap\ImapImportPlan;
use App\Tests\Infrastructure\ImapClient\FakeImapServer;
use App\Tests\Infrastructure\ImapClient\UsesDovecot;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The first time a folder is met, the mark for new mail goes to its top and
 * its history is left for the importer.
 *
 * That one move is what makes a new account show today's mail first. Without
 * it the mark starts at nothing and the first sync asks for `1:*` — the whole
 * folder, oldest first, in the job that was only meant to look (#42).
 *
 * Against the test stack's Dovecot, whose UIDNEXT is a real one, and against
 * FakeImapServer for the server that does not say what its next UID will be.
 */
final class ImapImportPlanTest extends TestCase
{
    use UsesDovecot;

    public function testAFolderIsPlannedFromItsTopWithEverythingBelowLeftAsHistory(): void
    {
        $mailbox = $this->mailbox();

        self::assertTrue($this->plan()->begin($mailbox, $this->holding(3)));

        self::assertSame(3, $mailbox->lastSeenUid, 'new mail is whatever comes after the newest message there is');
        self::assertSame(4, $mailbox->importFloorUid, 'and all of what is there is history');
        self::assertTrue($mailbox->isImporting());
    }

    /** No history is no import, and saying so keeps the folder off the importer's list. */
    public function testAnEmptyFolderHasNothingToImport(): void
    {
        $mailbox = $this->mailbox();

        $this->plan()->begin($mailbox, $this->holding(0));

        self::assertSame(0, $mailbox->importFloorUid);
        self::assertFalse($mailbox->isImporting());
    }

    /**
     * A folder the old oldest-first pass left half read has a mark in its
     * middle. It goes up to the top, never down, and the gap is history.
     */
    public function testAMarkLeftInTheMiddleOfAFolderOnlyEverMovesUp(): void
    {
        $session = $this->holding(3);

        $mailbox              = $this->mailbox();
        $mailbox->lastSeenUid = 1;

        $this->plan()->begin($mailbox, $session);

        self::assertSame(3, $mailbox->lastSeenUid);

        $ahead              = $this->mailbox();
        $ahead->lastSeenUid = 9000;

        $this->plan()->begin($ahead, $session);

        self::assertSame(9000, $ahead->lastSeenUid);
    }

    /**
     * A server that will not say where its top is has not given enough to
     * plan with. The folder stays unplanned and is read the old way, which is
     * slow and correct — the alternative is guessing which mail is new.
     */
    public function testAServerThatDoesNotNameItsNextUidLeavesTheFolderUnplanned(): void
    {
        $server = new FakeImapServer(new ImapLoop());
        $server->on('EXAMINE', static fn (): array => ['* 3 EXISTS', '* OK [UIDVALIDITY 7] UIDs valid', 'OK [READ-ONLY] Examined']);

        $mailbox = $this->mailbox();

        self::assertFalse($this->plan()->begin($mailbox, $server->session()));

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

    /** A Dovecot INBOX with this many messages in it, UIDs 1 to $count. */
    private function holding(int $count): ImapSession
    {
        $session = $this->dovecotSession();

        for ($uid = 1; $uid <= $count; ++$uid) {
            $session->append('INBOX', sprintf("Subject: %d\r\n\r\nBody.\r\n", $uid));
        }

        return $session;
    }
}
