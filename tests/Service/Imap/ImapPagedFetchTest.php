<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Service\Imap\ImapPagedFetch;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Exceptions\GetMessagesFailedException;
use Webklex\PHPIMAP\Exceptions\MessageContentFetchingException;
use Webklex\PHPIMAP\Message;

/**
 * One message the library cannot read costs that message, not the fetch.
 *
 * The library's own paging gave up the whole fetch for one unreadable message,
 * so the folder it was in never synced again (#45). Its soft-fail setting is
 * the obvious answer and cannot be used with its own loop, which runs until
 * the messages handed over add up to the messages found — a number a dropped
 * message makes unreachable, so it asks for empty pages for ever. Both faults
 * are pinned here: every page is fetched exactly once, and what was dropped is
 * handed over with the page it was dropped from.
 *
 * Against a scripted query (see FakeQuery) rather than a server: the claims
 * are about a loop — how many times it goes round, and what it reports each
 * time — and a server adds nothing to them but a way to be slow.
 */
final class ImapPagedFetchTest extends TestCase
{
    public function testAnUnreadableMessageIsReportedWithItsPageAndTheRestStillArrive(): void
    {
        $query = FakeQuery::finding([
            1 => $this->mail(1),
            2 => new MessageContentFetchingException('no content found'),
            3 => $this->mail(3),
            4 => $this->mail(4),
            5 => $this->mail(5),
        ]);

        $pages = $this->collect($query, 2);

        self::assertSame([[1], [3, 4], [5]], array_column($pages, 'uids'), 'everything readable arrives, page by page');
        self::assertSame([[2], [], []], array_column($pages, 'unreadable'), 'and the one that is not is named once, with its page');
    }

    /**
     * The loop this replaces counted what it handed over until it reached what
     * the search found. With one message dropped it never got there.
     */
    public function testEveryPageIsAskedForOnceEvenWhenAMessageIsDropped(): void
    {
        $query = FakeQuery::finding([
            1 => new MessageContentFetchingException('no content found'),
            2 => $this->mail(2),
            3 => $this->mail(3),
        ]);

        $this->collect($query, 2);

        self::assertSame([1, 2], $query->fetchedPages);
    }

    /** A whole page of them is still a page: nothing arrives, and the fetch goes on. */
    public function testAPageOfNothingButUnreadableMessagesDoesNotEndTheFetch(): void
    {
        $query = FakeQuery::finding([
            1 => new MessageContentFetchingException('no content found'),
            2 => new MessageContentFetchingException('no content found'),
            3 => $this->mail(3),
        ]);

        $pages = $this->collect($query, 2);

        self::assertSame([[], [3]], array_column($pages, 'uids'));
        self::assertSame([[1, 2], []], array_column($pages, 'unreadable'));
    }

    public function testASearchThatFindsNothingFetchesNothing(): void
    {
        $query = FakeQuery::finding([]);

        self::assertSame([], $this->collect($query, 50));
        self::assertSame([], $query->fetchedPages);
    }

    /**
     * The contrast that says the fake is honest: left as the library ships,
     * the same script ends at the unreadable message. If this did not throw,
     * the tests above would be passing against a query that never failed.
     */
    public function testWithoutSoftFailTheSameSearchEndsAtTheUnreadableMessage(): void
    {
        $query = FakeQuery::finding([
            1 => $this->mail(1),
            2 => new MessageContentFetchingException('no content found'),
        ]);

        $this->expectException(GetMessagesFailedException::class);

        $query->limit(50, 1)->curate_messages($query->search());
    }

    /**
     * @return list<array{uids: list<int>, unreadable: list<int>}>
     */
    private function collect(FakeQuery $query, int $pageSize): array
    {
        $pages = [];

        foreach (new ImapPagedFetch()->pages($query, $pageSize) as [$batch, $unreadable]) {
            $uids = [];

            foreach ($batch as $message) {
                $uids[] = (int) $message->getUid();
            }

            $pages[] = ['uids' => $uids, 'unreadable' => array_keys($unreadable)];
        }

        return $pages;
    }

    private function mail(int $uid): Message
    {
        $raw = "From: sender@example.test\r\n"
            . sprintf("Subject: Mail %d\r\n", $uid)
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "hello\r\n";

        return Message::fromString($raw, ImapConnectionFactory::config())->setUid($uid);
    }
}
