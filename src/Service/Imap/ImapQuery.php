<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Helper\RepeatedSingletonHeaders;
use Illuminate\Support\Collection;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * The library's search, with two of its answers corrected for the servers
 * that give them (#219).
 *
 * ── A header the parser cannot read costs that message ───────────────────────
 * webklex builds each fetched message in Query::make() and catches what it
 * expects a single message to throw, so with soft-fail on (see ImapPagedFetch)
 * the message is dropped and the page goes on. Its header parser throws other
 * things too: a message with a repeated Date makes it pass an array to
 * preg_match(), a TypeError, or a date with trailing data to Carbon, an
 * InvalidArgumentException — see RepeatedSingletonHeaders. Neither is caught
 * there, so one such message failed its whole page, every sync, and the import
 * gave up on the page after its retries.
 *
 * So make() here catches them. It first tries the message again with the
 * repeated once-only headers collapsed, which is the case seen, and puts the
 * raw header the server sent back on the message, so the .eml is the mail as
 * it arrived. A message that still will not build is recorded against its UID
 * the way the library records its own failures, which is what ImapPagedFetch
 * hands on, and costs that message and no other.
 *
 * Only these three kinds of throwable. A TypeError or a ValueError out of the
 * parser is a value it did not expect, and an InvalidArgumentException is
 * Carbon refusing a date; all three are properties of the one message. The
 * library's own exceptions keep their meaning — a connection that failed while
 * building a message is not a message problem — and pass through unchanged.
 *
 * ── "No such message" from SEARCH can mean "none" ─────────────────────────────
 * Proton Mail Bridge answered the sync's `UID SEARCH n:*` with
 * `NO no such message` (#219), and the folder's sync failed. RFC 3501 has no
 * such answer for a search — one that matches nothing is `OK` with an empty
 * list — and a range a server cannot find a message in is exactly one that
 * matches nothing, most likely a folder with no new mail or none at all.
 * emptyWhenNoSuchMessage() reads that answer as the empty list.
 *
 * Asked for, not assumed. A range search (`n:*`, `1:n`) that finds nothing
 * moves nothing: the next run asks the same range again. A search for a set of
 * UIDs is different — the importer asks for a page of UIDs it already found,
 * and if the server refuses the set because ONE of them has gone since, "none"
 * would let the importer move past the rest for good. That one keeps failing,
 * and is retried, as before.
 */
class ImapQuery extends WhereQuery
{
    private bool $noSuchMessageIsEmpty = false;

    /**
     * The search for this folder, as Folder::messages() opens it, as one of
     * these.
     *
     * A query that already is one is passed through: that is a test's double
     * of the server (see FakeQuery), handed out by a test's folder.
     */
    public static function of(Folder $folder): self
    {
        $query = $folder->messages();

        if ($query instanceof self) {
            return $query;
        }

        return new self($query->getClient(), $query->getExtensions());
    }

    /** Read "NO no such message" from SEARCH as an empty result; see the class docblock. */
    public function emptyWhenNoSuchMessage(): static
    {
        $this->noSuchMessageIsEmpty = true;

        return $this;
    }

    public function search(): Collection
    {
        try {
            return $this->searchOnServer();
        } catch (ImapServerErrorException $refused) {
            if (true === $this->noSuchMessageIsEmpty && true === self::saysNoSuchMessage($refused)) {
                return new Collection([]);
            }

            throw $refused;
        }
    }

    /** Whether the server's NO was the one Proton Mail Bridge gives for "found nothing". */
    public static function saysNoSuchMessage(ImapServerErrorException $refused): bool
    {
        return 1 === preg_match('~\bno such message\b~i', $refused->getMessage());
    }

    /**
     * @param array<int|string, mixed> $flags
     */
    protected function make(int $uid, int $msglist, string $header, string $content, array $flags): ?Message
    {
        try {
            return $this->build($uid, $msglist, $header, $content, $flags);
        } catch (\TypeError|\ValueError|\InvalidArgumentException $failure) {
            // Handled below, where the second try's failure joins it.
        }

        $repaired = RepeatedSingletonHeaders::keepFirst($header);

        if ($repaired !== $header) {
            try {
                $message = $this->build($uid, $msglist, $repaired, $content, $flags);
                $built = $message?->getHeader();

                // The mail as the server sent it, for the .eml and for
                // RepeatedHeaderHelper, which compares against these bytes.
                if (null !== $built) {
                    $built->raw = $header;
                }

                return $message;
            } catch (\TypeError|\ValueError|\InvalidArgumentException $failure) {
                // The repair was not enough; recorded below like the first.
            }
        }

        $this->setError($uid, new \RuntimeException(
            sprintf('The header of message %d could not be read: %s', $uid, $failure->getMessage()),
            0,
            $failure,
        ));
        $this->handleException($uid);

        return null;
    }

    /**
     * The library's own make(), apart so a test can stand in for it: the real
     * one needs a connected client to build a message.
     *
     * @param array<int|string, mixed> $flags
     */
    protected function build(int $uid, int $msglist, string $header, string $content, array $flags): ?Message
    {
        return parent::make($uid, $msglist, $header, $content, $flags);
    }

    /** The library's own search(), apart for the same reason as build(). */
    protected function searchOnServer(): Collection
    {
        return parent::search();
    }
}
