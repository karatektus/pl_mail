<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSession;
use App\Service\Imap\ImapPagedFetch;
use App\Tests\Infrastructure\ImapClient\FakeImapServer;
use App\Tests\Infrastructure\ImapClient\UsesDovecot;
use PHPUnit\Framework\TestCase;

/**
 * One message that cannot be read costs that message, not the fetch.
 *
 * The library's own paging gave up the whole fetch for one unreadable message,
 * so the folder it was in never synced again (#45), and its soft-fail mode
 * asked for empty pages for ever once a message was dropped. Pinned here:
 * every page is fetched once, a message the parser throws on is handed over
 * with the page it was on, and so is one the server listed and then
 * returned nothing for (#96).
 *
 * Against Dovecot where a real server shows it — a message that will not
 * parse is put on the server as it is, and the flags come back as the server
 * set them — and against FakeImapServer for the message that is listed and
 * never returned, which no well-run server does on request.
 */
final class ImapPagedFetchTest extends TestCase
{
    use UsesDovecot;

    private const string PLAIN = "From: a@example.test\r\nSubject: Fine\r\nMessage-ID: <fine-%d@example.test>\r\n\r\nHello.\r\n";

    /** A multipart message with no boundary: the parser finds no content and throws. */
    private const string UNREADABLE = "From: a@example.test\r\nSubject: Broken\r\nMessage-ID: <broken@example.test>\r\nContent-Type: multipart/mixed\r\n\r\n--\r\nhello\r\n";

    public function testAnUnreadableMessageIsReportedWithItsPageAndTheRestStillArrive(): void
    {
        $session = $this->dovecotSession();

        foreach ([1, 2, 3, 4, 5] as $uid) {
            $session->append('INBOX', 3 === $uid ? self::UNREADABLE : sprintf(self::PLAIN, $uid));
        }

        $pages = $this->pages($session, [1, 2, 3, 4, 5], 2);

        $session->logout();

        self::assertSame([
            ['uids' => [1, 2], 'unreadable' => []],
            ['uids' => [4], 'unreadable' => [3]],
            ['uids' => [5], 'unreadable' => []],
        ], $pages, 'every page once, the unreadable one reported beside its page');
    }

    /**
     * Flags arrive in the app's own spelling, keywords kept: webklex stored
     * `Seen` and dropped every keyword, and an IMAP draft stored as `Draft`
     * was not a draft to the app.
     */
    public function testFlagsArriveAsTheAppSpellsThem(): void
    {
        $session = $this->dovecotSession();
        $session->append('INBOX', sprintf(self::PLAIN, 1), ['\\Draft', '\\Seen', '$Forwarded']);

        [[$messages]] = iterator_to_array(new ImapPagedFetch()->pages($session, 'INBOX', [1], 10));

        $session->logout();

        self::assertSame(['$Forwarded', '\\Draft', '\\Seen'], array_values($messages[0]->getFlags()->toArray()));
        self::assertSame(1, $messages[0]->getUid());
    }

    public function testAMessageTheServerListsAndNeverReturnsIsReportedRatherThanLost(): void
    {
        $server = new FakeImapServer(new ImapLoop());
        $server->messages['INBOX'] = [1 => sprintf(self::PLAIN, 1), 2 => null, 3 => sprintf(self::PLAIN, 3)];

        self::assertSame(
            [['uids' => [1, 3], 'unreadable' => [2]]],
            $this->pages($server->session(), [1, 2, 3], 10),
        );
    }

    public function testNothingToFetchFetchesNothing(): void
    {
        $server = new FakeImapServer(new ImapLoop());

        self::assertSame([], $this->pages($server->session(), [], 10));
        self::assertSame([], $server->received('UID FETCH'));
    }

    /**
     * @param list<int> $uids
     *
     * @return list<array{uids: list<int>, unreadable: list<int>}>
     */
    private function pages(ImapSession $session, array $uids, int $pageSize): array
    {
        $pages = [];

        foreach (new ImapPagedFetch()->pages($session, 'INBOX', $uids, $pageSize) as [$messages, $unreadable]) {
            $pages[] = [
                'uids'       => array_map(static fn ($message): int => (int) $message->getUid(), $messages),
                'unreadable' => array_keys($unreadable),
            ];
        }

        return $pages;
    }
}
