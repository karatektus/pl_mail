<?php

declare(strict_types=1);

namespace App\Service\Task\Sync;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\TaskSyncDriverInterface;
use App\Entity\Task\TaskList;

/** Finds the driver that speaks for a source, as CalendarSyncDriverRegistry does. */
final readonly class TaskSyncDriverRegistry
{
    /**
     * @param iterable<TaskSyncDriverInterface> $drivers
     */
    public function __construct(
        private iterable $drivers,
    ) {}

    /** @throws CalendarSyncPermanentException */
    public function for(CalendarSource $source): TaskSyncDriverInterface
    {
        foreach ($this->drivers as $driver) {
            if (true === $driver->supports($source)) {
                return $driver;
            }
        }

        throw new CalendarSyncPermanentException('No task service is configured for this account or connection.');
    }

    /** @throws CalendarSyncPermanentException */
    public function forList(TaskList $list): TaskSyncDriverInterface
    {
        $source = self::sourceOf($list);

        if (null === $source) {
            throw new CalendarSyncPermanentException('This list is not connected to anything, so there is nothing to sync it with.');
        }

        return $this->for($source);
    }

    public function has(CalendarSource $source): bool
    {
        foreach ($this->drivers as $driver) {
            if (true === $driver->supports($source)) {
                return true;
            }
        }

        return false;
    }

    public static function sourceOf(TaskList $list): ?CalendarSource
    {
        return match (true) {
            null !== $list->integration => CalendarSource::ofIntegration($list->integration),
            null !== $list->account     => CalendarSource::ofAccount($list->account),
            default                     => null,
        };
    }
}
