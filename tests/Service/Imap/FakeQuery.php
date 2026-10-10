<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use Webklex\PHPIMAP\Exceptions\GetMessagesFailedException;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\Query;
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
 * Made with newInstanceWithoutConstructor(), because the real constructor asks
 * a connected client for its configuration.
 */
final class FakeQuery extends Query
{
    /** @var array<int, Message|\Throwable> UID => the message, or what building it throws */
    private array $script = [];

    /** @var list<int> the page number of every fetch, in order */
    public array $fetchedPages = [];

    /**
     * @param array<int, Message|\Throwable> $script
     */
    public static function finding(array $script): self
    {
        $query = new \ReflectionClass(self::class)->newInstanceWithoutConstructor();
        $query->script = $script;

        return $query;
    }

    public function search(): Collection
    {
        return new Collection(array_keys($this->script));
    }

    public function curate_messages(Collection $available_messages): MessageCollection
    {
        $this->fetchedPages[] = $this->page;

        $messages = MessageCollection::make();

        foreach ($available_messages->forPage($this->page, $this->limit) as $uid) {
            $entry = $this->script[$uid];

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
