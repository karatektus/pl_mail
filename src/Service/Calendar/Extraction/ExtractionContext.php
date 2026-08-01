<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;

/**
 * Everything an extractor may look at, assembled once per message.
 *
 * Assembled rather than fetched on demand so that every extractor sees the
 * same message: three of them each calling getMessageParts() would be three
 * queries for one answer, and the parts list is the thing they all start from.
 *
 * Deliberately all persisted data. That is the property the whole design rests
 * on — the same context can be rebuilt months later from the row alone, which
 * is what makes `app:backfill events` a backfill rather than a resync. The one
 * exception is $rawMime, and it is a closure precisely because it is not free:
 * on Graph it costs an API call, so it is resolved only if an extractor
 * actually asks.
 */
final class ExtractionContext
{
    /** @var callable(): ?string */
    private $rawMimeLoader;

    private bool $rawMimeLoaded = false;

    private ?string $rawMime = null;

    /**
     * @param list<MessagePart>   $calendarParts parts whose content type is text/calendar
     * @param array<string,mixed> $headers       normalised, as stored on the row
     * @param callable(): ?string $rawMimeLoader
     */
    public function __construct(
        public readonly Message $message,
        public readonly Account $account,
        public readonly array   $calendarParts,
        public readonly ?string $bodyHtml,
        public readonly array   $headers,
        public readonly string  $fromAddress,
        callable                $rawMimeLoader,
    ) {
        $this->rawMimeLoader = $rawMimeLoader;
    }

    /**
     * The original RFC822 bytes, fetched at most once.
     *
     * Only Graph invites need this — its object model has no place for the
     * text/calendar part, so the MIME is the only way to reach it. Memoised
     * including the null, so a message with no raw available is not asked for
     * repeatedly by every extractor in the cascade.
     */
    public function rawMime(): ?string
    {
        if (false === $this->rawMimeLoaded) {
            $this->rawMimeLoaded = true;
            $this->rawMime       = ($this->rawMimeLoader)();
        }

        return $this->rawMime;
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[mb_strtolower($name)] ?? null;

        return is_string($value) ? $value : null;
    }
}
