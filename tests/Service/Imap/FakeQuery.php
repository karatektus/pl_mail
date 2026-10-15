<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\GetMessagesFailedException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;
use Illuminate\Support\Collection;

/**
 * A search with a scripted answer: which UIDs it finds, and what building each
 * one into a message does.
 *
 * It keeps the library's own behaviour in the two places ImapPagedFetch
 * depends on, copied from Query rather than imagined: a message that cannot be
 * built is recorded against its UID, and whether that ends the fetch is decided
 * by soft-fail alone. Everything that would need a server — the search, the
 * fetch — is replaced by the script.
 *
 * It understands the one kind of criterion this application's syncers write —
 * `CUSTOM UID …`, a range or a set (see MessageSyncer::uidRangeCriteria()) —
 * and answers the search with the scripted UIDs that fall inside it, so a
 * caller that asks for "everything below 120" and then for "117, 118 and 119"
 * is answered as a server would answer.
 *
 * Made with newInstanceWithoutConstructor(), because the real constructor asks
 * a connected client for its configuration.
 */
final class FakeQuery extends WhereQuery
{
    /** @var array<int, Message|\Throwable|string> UID => the message, what building it throws, or NEVER_RETURNED */
    private array $script = [];

    /** @var list<int> the page number of every fetch, in order */
    public array $fetchedPages = [];

    /** @var list<int>|null the UIDs the criterion names, or null for all */
    private ?array $wanted = null;

    /** @var array{0: int, 1: int}|null the UID range the criterion names */
    private ?array $range = null;

    /** @var (\Closure(list<int>): void)|null told the UIDs of every page fetched */
    private ?\Closure $onFetch = null;

    /**
     * @param array<int, Message|\Throwable|string> $script
     * @param (\Closure(list<int>): void)|null $onFetch
     */
    public static function finding(array $script, ?\Closure $onFetch = null): self
    {
        $query = new \ReflectionClass(self::class)->newInstanceWithoutConstructor();
        $query->script  = $script;
        $query->onFetch = $onFetch;

        return $query;
    }

    public function where(mixed $criteria, mixed $value = null): static
    {
        $criteria = (string) $criteria;

        if (1 === preg_match('~^CUSTOM UID (\d+):(\d+|\*)$~', $criteria, $m)) {
            $this->range = [(int) $m[1], '*' === $m[2] ? PHP_INT_MAX : (int) $m[2]];
        } elseif (1 === preg_match('~^CUSTOM UID ([\d,]+)$~', $criteria, $m)) {
            $this->wanted = array_map('intval', explode(',', $m[1]));
        } else {
            throw new \LogicException(sprintf('FakeQuery does not understand the criterion "%s".', $criteria));
        }

        return $this;
    }

    public function search(): Collection
    {
        $uids = array_keys($this->script);

        if (null !== $this->range) {
            [$from, $to] = $this->range;
            $uids        = array_values(array_filter($uids, static fn (int $uid): bool => $uid >= $from && $uid <= $to));
        }

        if (null !== $this->wanted) {
            $uids = array_values(array_intersect($uids, $this->wanted));
        }

        return new Collection($uids);
    }

    /** A UID the server lists in SEARCH and returns nothing for in FETCH. */
    public const string NEVER_RETURNED = 'listed, never returned';

    /** Thrown from every page fetch when set — the connection dying mid-command, say. */
    public ?\Throwable $pageFailure = null;

    public function curate_messages(Collection $available_messages): MessageCollection
    {
        $this->fetchedPages[] = $this->page;

        $messages = MessageCollection::make();

        if (null !== $this->pageFailure) {
            // Query::curate_messages() wraps whatever populate() throws.
            throw new GetMessagesFailedException($this->pageFailure->getMessage(), 0, $this->pageFailure);
        }

        $asked = $available_messages->forPage($this->page, $this->limit)->all();
        $given = array_filter($asked, fn (int $uid): bool => self::NEVER_RETURNED !== $this->script[$uid]);

        // What the real library does with a FETCH the server completed without
        // a single FETCH line: its Response is neither data nor allowed to be
        // empty, validatedData() throws, and soft-fail does not come into it.
        // One absent UID among others that came back is NOT this — the answer
        // has data, and the absent one is simply not in it.
        if ([] !== $asked && [] === $given) {
            $empty = ResponseException::make(Response::empty());

            throw new GetMessagesFailedException($empty->getMessage(), 0, $empty);
        }

        if (null !== $this->onFetch) {
            ($this->onFetch)(array_values($available_messages->forPage($this->page, $this->limit)->all()));
        }

        foreach ($available_messages->forPage($this->page, $this->limit) as $uid) {
            $entry = $this->script[$uid];

            if (self::NEVER_RETURNED === $entry) {
                continue;
            }

            if ($entry instanceof \Throwable) {
                $this->setError($uid, $entry instanceof \Exception ? $entry : new \RuntimeException($entry->getMessage()));

                // Query::handleException(), which is what makes one unreadable
                // message end a fetch the library was not told to soften.
                if (false === $this->soft_fail) {
                    throw new GetMessagesFailedException($entry->getMessage(), 0, $entry);
                }

                continue;
            }

            $messages->put((string) $uid, $entry);
        }

        return $messages;
    }
}
