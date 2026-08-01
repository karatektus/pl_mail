<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction\StructuredData;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One schema.org type, understood.
 *
 * The same shape as EventExtractorInterface one level up, for the same reason:
 * schema.org has hundreds of types and this project will keep meeting new ones,
 * so adding support for a type has to be writing a class and nothing else. A
 * match arm over @type inside the extractor would grow into a four-hundred-line
 * method that no one can review a single type's mapping in.
 *
 * Implementations are auto-tagged app.structured_data_mapper and indexed by the
 * terms they claim. There is no priority: a type has one mapper, and two
 * mappers claiming one type is a mistake rather than a fallback chain.
 */
#[AutoconfigureTag('app.structured_data_mapper')]
interface StructuredDataMapperInterface
{
    /**
     * Bare schema.org terms, not URLs — Node::type() has already reduced
     * https://schema.org/FlightReservation to FlightReservation.
     *
     * @return list<string>
     */
    public function types(): array;

    /**
     * @return list<MappedEvent> empty when the node carries no date worth
     *                           putting on a calendar, which is the normal
     *                           outcome and never an error
     */
    public function map(Node $node): array;
}
