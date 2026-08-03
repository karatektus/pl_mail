<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction;

use App\Domain\Interface\EventExtractorInterface;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Service\Mail\RawMessageResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs the extractors over one message and returns what they found.
 *
 * First-wins per dedup key rather than first-wins overall. A message can carry
 * several unrelated events — a two-leg flight, an order with three parcels —
 * and an invite can sit beside a booking confirmation, so every extractor gets
 * to look and only a collision on the same key is resolved by priority. The
 * one exception is an extractor that stops the cascade, which exists because a
 * real invite is authoritative and nothing below it can improve on it.
 *
 * Decides nothing about the calendar. What it returns is a set of claims;
 * EventReconciler decides which become rows.
 */
final readonly class EventExtractionRunner
{
    /**
     * @param iterable<EventExtractorInterface> $extractors
     */
    public function __construct(
        private RawMessageResolver $rawResolver,
        private LoggerInterface    $logger,
        #[AutowireIterator('app.event_extractor')]
        private iterable           $extractors,
    ) {
    }

    /**
     * @return list<ExtractedEvent>
     */
    public function run(Message $message): array
    {
        $context = $this->contextFor($message);

        /** @var array<string, ExtractedEvent> $byKey */
        $byKey = [];

        foreach ($this->ordered() as $extractor) {
            try {
                if (false === $extractor->supports($context)) {
                    continue;
                }

                foreach ($extractor->extract($context) as $event) {
                    // Higher priority ran first, so an existing key stays. The
                    // loser is not an error: two extractors agreeing about one
                    // booking is the system working.
                    $byKey[$event->dedupKey] ??= $event;
                }
            } catch (\Throwable $e) {
                // One broken extractor must not cost the events the others
                // found, nor the message.
                $this->logger->error('EventExtraction: extractor failed', [
                    'extractor' => $extractor::class,
                    'messageId' => $message->id,
                    'error'     => $e->getMessage(),
                ]);

                continue;
            }

            if (true === $extractor->stopsCascade() && [] !== $byKey) {
                break;
            }
        }

        return array_values($byKey);
    }

    /**
     * @return list<EventExtractorInterface>
     */
    private function ordered(): array
    {
        $extractors = iterator_to_array($this->extractors, false);

        usort(
            $extractors,
            static fn (EventExtractorInterface $a, EventExtractorInterface $b): int
                => $b->priority() <=> $a->priority(),
        );

        return $extractors;
    }

    private function contextFor(Message $message): ExtractionContext
    {
        $calendarParts = [];

        foreach ($message->messageParts as $part) {
            if (true === $this->isCalendar($part)) {
                $calendarParts[] = $part;
            }
        }

        return new ExtractionContext(
            message:       $message,
            account:       $message->account,
            calendarParts: $calendarParts,
            bodyHtml:      $message->bodyHtml,
            headers:       $message->headers ?? [],
            fromAddress:   mb_strtolower(trim((string) $message->fromAddress)),
            // Lazy: on Graph this is an API call, and most messages are not
            // invites.
            rawMimeLoader: function () use ($message): ?string {
                $path = $this->rawResolver->absolutePathFor($message);

                if (null === $path || false === is_file($path)) {
                    return null;
                }

                $bytes = @file_get_contents($path);

                return false === $bytes ? null : $bytes;
            },
        );
    }

    /**
     * Content type only, never disposition. Gmail invites are stored inline so
     * they do not raise a paperclip, and an IMAP one may be either.
     */
    private function isCalendar(MessagePart $part): bool
    {
        $type = mb_strtolower(trim((string) $part->contentType));

        return in_array($type, ['text/calendar', 'application/ics'], true);
    }
}
