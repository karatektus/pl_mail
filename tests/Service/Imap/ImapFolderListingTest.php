<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Entity\Mail\Mailbox;
use App\Service\Imap\ImapFolderListing;
use App\Tests\Infrastructure\ImapClient\UsesDovecot;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What a folder holds, read in one EXAMINE and one FETCH, against the test
 * stack's Dovecot.
 *
 * The empty folder is the case that went wrong. webklex raised "OK, and here
 * is nothing" as a failure, the listing reported the server as not answering,
 * and the folder was never swept: mail deleted from it elsewhere stayed here,
 * and an unswept folder withholds reaping from the whole account. An empty
 * Trash or Junk is the commonest answer there is.
 */
final class ImapFolderListingTest extends TestCase
{
    use UsesDovecot;

    public function testAnEmptyFolderIsAnEmptyListingRatherThanNoAnswer(): void
    {
        $session = $this->dovecotSession();
        $session->create('Trash');

        $listing = new ImapFolderListing(new NullLogger())->read($session, $this->mailbox('Trash'));

        $session->logout();

        self::assertNotNull($listing, 'the server answered');
        self::assertSame([], $listing['uids']);
        self::assertGreaterThan(0, $listing['uidValidity']);
    }

    /** Presence and flags from the same instant, as the server spells the flags. */
    public function testAFolderListsEveryUidWithItsFlags(): void
    {
        $session = $this->dovecotSession();
        $session->append('INBOX', "Subject: one\r\n\r\n1\r\n", ['\\Seen']);
        $session->append('INBOX', "Subject: two\r\n\r\n2\r\n");

        $listing = new ImapFolderListing(new NullLogger())->read($session, $this->mailbox('INBOX'));

        $session->logout();

        self::assertNotNull($listing);
        self::assertSame([1 => true, 2 => true], $listing['uids']);
        self::assertContains('\\Seen', $listing['flags'][1]);
        self::assertNotContains('\\Seen', $listing['flags'][2]);
    }

    /** A folder the server does not have is no answer: null is never evidence that mail is gone. */
    public function testAFolderThatIsNotThereIsNoAnswer(): void
    {
        $session = $this->dovecotSession();

        self::assertNull(new ImapFolderListing(new NullLogger())->read($session, $this->mailbox('Not There')));

        $session->logout();
    }

    private function mailbox(string $path): Mailbox
    {
        $mailbox           = new Mailbox();
        $mailbox->name     = $path;
        $mailbox->fullPath = $path;

        return $mailbox;
    }
}
