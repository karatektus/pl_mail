<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Listener;

use App\Entity\Calendar\CalendarEvent;
use App\Entity\Note\Note;
use App\Service\Push\ObjectChangeNudges;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Notes which users' calendars and notes a flush changed, so their phones can
 * be told (ObjectChangeNudges → JmapPushSubscriber → a StateChange naming
 * CalendarEvent or Note). That is what lets a widget show an edit made on the
 * web without the app being opened.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class ObjectChangeNudgeListener
{
    public function __construct(private ObjectChangeNudges $nudges)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ([$uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates(), $uow->getScheduledEntityDeletions()] as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof CalendarEvent) {
                    $userId = $entity->usr->id ?? $entity->calendar->usr->id ?? null;
                    if (null !== $userId) {
                        $this->nudges->record((int) $userId, ObjectChangeNudges::CALENDAR);
                    }
                } elseif ($entity instanceof Note) {
                    if (null !== $entity->usr->id) {
                        $this->nudges->record((int) $entity->usr->id, ObjectChangeNudges::NOTES);
                    }
                }
            }
        }
    }
}
