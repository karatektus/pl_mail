<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Helper\MessageParser;
use App\Infrastructure\ImapClient\Exception\ImapException;
use App\Infrastructure\ImapClient\ImapSession;
use Webklex\PHPIMAP\Message as ImapMessage;
use Webklex\PHPIMAP\Support\FlagCollection;

/**
 * Messages fetched a page at a time, in which a message that cannot be read
 * costs that message and not the folder.
 *
 * Each page is one `UID FETCH (FLAGS BODY.PEEK[])` for its UIDs, and each
 * message is parsed from the bytes the server sent. Parsing is the library's
 * (MessageParser, until #82), and it is only parsing: no part of it talks to
 * the server, so a message it cannot read is a fact about that message, and a
 * connection that drops is never mistaken for one.
 *
 * Handed over with each page are the UIDs of it that did not become messages,
 * with why, for MessageSyncer::holdForRetry() to retry and eventually let go:
 *
 *   - one the parser threw on (#45, and the repeated-header case of #357,
 *     which MessageParser repairs first);
 *   - one the server listed and then returned nothing for. iCloud does this
 *     for a message deleted and not yet expunged (#96), and a page asking for
 *     it gets every other message and not that one.
 *
 * What ends the fetch is what should: the server going away, or refusing the
 * FETCH. That is not about one message, and the next page would fail too.
 *
 * WHAT A MESSAGE CARRIES
 * ──────────────────────
 * Its UID, and its flags in the app's own spelling (MessageFlag::canonicalList:
 * `\Seen`, keywords kept, \Recent dropped), the form every stored list is
 * in. Webklex, which fetched these before, stored `Seen` and dropped every
 * keyword, so an IMAP draft stored as `Draft` was not a draft to the app.
 */
final readonly class ImapPagedFetch
{
    /**
     * @param list<int> $uids in the order to fetch them
     *
     * @return iterable<array{list<ImapMessage>, array<int, \Throwable>}> each page's
     *         messages, and its UIDs that did not become one: UID => why
     */
    public function pages(ImapSession $session, string $path, array $uids, int $pageSize): iterable
    {
        foreach (array_chunk($uids, max(1, $pageSize)) as $page) {
            $fetched    = $session->uidFetch($path, ImapSession::uidSet($page), ['FLAGS', 'BODY.PEEK[]']);
            $messages   = [];
            $unreadable = [];

            foreach ($page as $uid) {
                $items = $fetched[$uid] ?? null;
                $raw   = $items['BODY[]'] ?? null;

                if (false === is_string($raw)) {
                    $unreadable[$uid] = new ImapException(sprintf('The server listed message %d and returned nothing for it.', $uid));

                    continue;
                }

                try {
                    $messages[] = self::message($uid, $raw, is_array($items['FLAGS'] ?? null) ? $items['FLAGS'] : []);
                } catch (\Throwable $failure) {
                    $unreadable[$uid] = new \RuntimeException(
                        sprintf('Message %d could not be read: %s', $uid, $failure->getMessage()),
                        0,
                        $failure,
                    );
                }
            }

            yield [$messages, $unreadable];
        }
    }

    /**
     * One message as the syncer takes it.
     *
     * @param list<mixed> $flags as the server sent them
     */
    public static function message(int $uid, string $raw, array $flags): ImapMessage
    {
        $message = MessageParser::fromString($raw, MessageParser::config());

        $message->setUid($uid);
        $message->setFlags(FlagCollection::make(MessageFlag::canonicalList(array_values(array_filter($flags, is_string(...))))));

        return $message;
    }
}
