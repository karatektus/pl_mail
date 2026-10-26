<?php

declare(strict_types=1);

namespace App\Service\Push;

/**
 * Users whose calendar or notes changed in this unit of work, waiting to be
 * told (JmapPushSubscriber sends it once the work is done, like a mail
 * StateChange). Filled by ObjectChangeNudgeListener; one entry per user and
 * type, however many events or notes changed.
 */
final class ObjectChangeNudges
{
    public const string CALENDAR = 'CalendarEvent';
    public const string NOTES    = 'Note';

    /** @var array<int, array<string, true>> */
    private array $pending = [];

    public function record(int $userId, string $type): void
    {
        $this->pending[$userId][$type] = true;
    }

    /**
     * @return array<int, list<string>> userId => types, emptying the buffer
     */
    public function drain(): array
    {
        $pending       = $this->pending;
        $this->pending = [];

        return array_map(static fn (array $types): array => array_keys($types), $pending);
    }
}
