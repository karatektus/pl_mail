<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * A named group of calendars that can be shown together, in the way
 * Fantastical's calendar sets work. Picking one shows its calendars and hides
 * the rest; see CalendarSets.
 */
final readonly class CalendarSet
{
    /**
     * @param list<int> $calendarIds
     */
    public function __construct(
        public string $id,
        public string $name,
        public array  $calendarIds,
    ) {
    }

    /** @return array{id: string, name: string, calendars: list<int>} */
    public function toSetting(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'calendars' => $this->calendarIds];
    }

    public static function fromSetting(mixed $row): ?self
    {
        if (false === is_array($row) || false === is_string($row['id'] ?? null) || false === is_string($row['name'] ?? null)) {
            return null;
        }

        $ids = is_array($row['calendars'] ?? null) ? $row['calendars'] : [];

        return new self(
            $row['id'],
            $row['name'],
            array_values(array_map('intval', array_filter($ids, 'is_numeric'))),
        );
    }
}
