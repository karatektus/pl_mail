<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\ImapConnection;
use App\Infrastructure\ImapClient\ImapCredentials;
use App\Infrastructure\ImapClient\ImapEndpoint;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSecurity;
use App\Infrastructure\ImapClient\ImapSession;
use PHPUnit\Framework\TestCase;

/**
 * Every command ImapSession sends, against the test stack's Dovecot.
 *
 * These are the commands sync, import, write-back and the folder tree run
 * on, so each is checked for what the server actually did, not only for
 * having been answered OK: a folder created is listed, a message moved is in
 * the other folder and not in this one, a flag stored is there on the next
 * fetch. Skipped outside the test stack; see DovecotImapTest.
 */
final class DovecotSessionTest extends TestCase
{
    private const int IMAPS_PORT = 31993;

    private ImapSession $session;

    protected function setUp(): void
    {
        $host     = getenv('IMAP_TEST_HOST');
        $password = getenv('IMAP_TEST_PASSWORD');

        if (false === $host || '' === $host || false === $password) {
            self::markTestSkipped('Set IMAP_TEST_HOST and IMAP_TEST_PASSWORD to run against an IMAP server; the test stack does.');
        }

        $connection = ImapConnection::open(new ImapLoop(), new ImapEndpoint($host, self::IMAPS_PORT, ImapSecurity::Tls, false));
        $connection->authenticate(new ImapCredentials('session-' . bin2hex(random_bytes(6)), $password));

        $this->session = new ImapSession($connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->session)) {
            $this->session->logout();
        }
    }

    /**
     * The folder tree, including a name that is not ASCII: the path comes
     * back in modified UTF-7, and the name decoded.
     */
    public function testFoldersAreCreatedListedRenamedAndDeleted(): void
    {
        $this->session->create('Archiv');
        $this->session->create('Archiv/Entw&APw-rfe');
        $this->session->subscribe('Archiv/Entw&APw-rfe');

        $folders = $this->byPath();

        self::assertArrayHasKey('INBOX', $folders);
        self::assertSame('Entwürfe', $folders['Archiv/Entw&APw-rfe']->name());
        self::assertSame('Archiv/Entwürfe', $folders['Archiv/Entw&APw-rfe']->fullName());
        self::assertSame('/', $folders['Archiv/Entw&APw-rfe']->delimiter);
        self::assertTrue($folders['Archiv']->hasAttribute('\\HasChildren'));

        $this->session->rename('Archiv/Entw&APw-rfe', 'Drafts old');
        $this->session->unsubscribe('Drafts old');
        $this->session->delete('Drafts old');

        self::assertArrayNotHasKey('Drafts old', $this->byPath());
        self::assertArrayNotHasKey('Archiv/Entw&APw-rfe', $this->byPath());
    }

    /**
     * A message appended comes back from FETCH as the bytes that went up,
     * with its flags, under the UID APPENDUID gave it.
     */
    public function testAnAppendedMessageIsFetchedBackWhole(): void
    {
        $message = "From: a@example.test\r\nSubject: Grüße\r\n\r\nBody line.\r\n";
        $uid     = $this->session->append('INBOX', $message, ['\\Seen'], new \DateTimeImmutable('2026-10-01 12:00:00 +0200'));

        self::assertSame(1, $uid);

        $state = $this->session->examine('INBOX');

        self::assertSame(1, $state->exists);
        self::assertSame(2, $state->uidNext);
        self::assertNotNull($state->uidValidity);
        self::assertTrue($state->readOnly);

        $fetched = $this->session->uidFetch('INBOX', '1:*', ['FLAGS', 'BODY.PEEK[]']);

        self::assertSame([1], array_keys($fetched));
        self::assertSame($message, $fetched[1]['BODY[]']);
        self::assertSame(['\\Seen'], $fetched[1]['FLAGS']);

        self::assertSame(['MESSAGES' => 1, 'UIDNEXT' => 2], $this->session->status('INBOX', ['MESSAGES', 'UIDNEXT']));
    }

    public function testSearchStoreMoveAndExpunge(): void
    {
        $this->session->create('Archive');

        foreach (['one', 'two', 'three'] as $subject) {
            $this->session->append('INBOX', sprintf("Subject: %s\r\n\r\n%s\r\n", $subject, $subject));
        }

        self::assertSame([1, 2, 3], $this->session->uidSearch('INBOX', 'ALL'));
        self::assertSame([2, 3], $this->session->uidSearch('INBOX', 'UID 2:*'));
        self::assertSame([3], $this->session->uidSearch('INBOX', 'UID 9:*'), 'a star range past the top answers with the top');

        $this->session->uidStore('INBOX', '1', '+', ['\\Flagged', '\\Seen']);
        $this->session->uidStore('INBOX', '1', '-', ['\\Seen']);

        $flags = $this->session->uidFetch('INBOX', '1', ['FLAGS'])[1]['FLAGS'];

        self::assertContains('\\Flagged', $flags);
        self::assertNotContains('\\Seen', $flags, 'removed again');

        $moved = $this->session->uidMove('INBOX', '2', 'Archive');

        self::assertSame([2 => 1], $moved, 'COPYUID names where it went');
        self::assertSame([1, 3], $this->session->uidSearch('INBOX', 'ALL'));
        self::assertSame([1], $this->session->uidSearch('Archive', 'ALL'));

        $copied = $this->session->uidCopy('INBOX', '3', 'Archive');

        self::assertSame([3 => 2], $copied);

        $this->session->uidStore('INBOX', '3', '+', ['\\Deleted']);
        $this->session->expunge('INBOX', '3');

        self::assertSame([1], $this->session->uidSearch('INBOX', 'ALL'));
    }

    public function testAnEmptyFolderFetchesNothing(): void
    {
        $this->session->create('Empty');

        self::assertSame([], $this->session->uidFetch('Empty', '1:*', ['FLAGS']));
        self::assertSame([], $this->session->uidSearch('Empty', 'ALL'));
    }

    public function testAUidSetIsWrittenAsItsRanges(): void
    {
        self::assertSame('3:5,9,11:12', ImapSession::uidSet([9, 3, 4, 5, 11, 12, 4]));
        self::assertSame('7', ImapSession::uidSet([7]));
    }

    /** @return array<string, \App\Infrastructure\ImapClient\ImapFolder> */
    private function byPath(): array
    {
        $folders = [];

        foreach ($this->session->folders() as $folder) {
            $folders[$folder->path] = $folder;
        }

        return $folders;
    }
}
