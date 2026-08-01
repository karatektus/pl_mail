<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Service\Calendar\Extraction\ExtractedEvent;
use App\Service\Calendar\Extraction\ExtractionContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One way of finding events in a message.
 *
 * Implementations are auto-tagged app.event_extractor and run in priority
 * order, highest first — the same shape as the integration drivers. Adding one
 * is writing a class; nothing else in the application changes.
 *
 * The cascade is first-wins per dedup key, NOT first-wins overall. One message
 * can legitimately carry several unrelated events — a two-leg flight, an order
 * with three parcels — and an invite can sit beside a booking confirmation. So
 * every extractor gets to look, and only a collision on the same key is
 * resolved by priority.
 *
 * stopsCascade() is the exception to that, and exists for exactly one case: a
 * real iCalendar invite is authoritative, and there is nothing a guess further
 * down the list can add to it.
 */
#[AutoconfigureTag('app.event_extractor')]
interface EventExtractorInterface
{
    /**
     * Cheap enough to call on every message. Anything expensive — parsing,
     * fetching raw MIME — belongs in extract(), which only runs when this
     * says yes.
     */
    public function supports(ExtractionContext $context): bool;

    /**
     * @return list<ExtractedEvent> empty when nothing was found, which is the
     *                              normal outcome and never an error
     */
    public function extract(ExtractionContext $context): array;

    /** Nothing after this extractor may look at the message. */
    public function stopsCascade(): bool;

    /** Highest first. */
    public function priority(): int;
}
