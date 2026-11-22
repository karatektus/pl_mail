<?php

declare(strict_types=1);

namespace App\Service\Task\Sync;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Task\RemoteTaskList;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\Calendar\Calendar;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncTaskListMessage;
use App\Repository\Task\TaskListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Turning the subscribe dialog's tick boxes into lists, and back.
 *
 * CalendarSubscriber's shape: the set of ticked ids is the whole instruction, and
 * **nothing the browser posted describes a list**. Only the ids are read from the
 * request; name, colour and read-only come from a fresh discover() on the way
 * through, because a crafted post claiming a list is writable would otherwise be
 * believed by an engine that trusts the flag.
 *
 * Stopping a mirror removes the local list and its tasks, not the remote's.
 */
final readonly class TaskListSubscriber
{
    public function __construct(
        private TaskSyncService        $sync,
        private TaskListRepository     $lists,
        private EntityManagerInterface $em,
        private MessageBusInterface    $bus,
    ) {}

    /**
     * Every list the source offers, each beside the row mirroring it if any.
     *
     * @return list<array{remote: RemoteTaskList, local: TaskList|null}>
     *
     * @throws CalendarSyncException
     */
    public function offered(CalendarSource $source): array
    {
        $mirrored = $this->lists->findForSource($source);
        $offered  = [];

        foreach ($this->sync->discover($source) as $remote) {
            $offered[] = ['remote' => $remote, 'local' => $mirrored[$remote->remoteId] ?? null];
        }

        return $offered;
    }

    /**
     * @param list<string>          $ticked  remote ids
     * @param array<string, string> $colours a colour picked in the dialog, by remote id, already one of Calendar::COLORS
     *
     * @throws CalendarSyncException
     */
    public function apply(User $user, CalendarSource $source, array $ticked, array $colours = []): void
    {
        $mirrored = $this->lists->findForSource($source);
        $created  = [];

        // Discovered before anything is written, so a listing that failed halfway
        // cannot read as "these lists are gone".
        foreach ($this->sync->discover($source) as $remote) {
            $local = $mirrored[$remote->remoteId] ?? null;

            if (true === in_array($remote->remoteId, $ticked, true)) {
                if (null === $local) {
                    $local     = $this->mirror($user, $source, $remote);
                    $created[] = $local;
                } else {
                    $local->name       = $remote->name;
                    $local->isReadOnly = $remote->isReadOnly;
                }

                // plMail's own, like the colour of any list: the remote never hears of it.
                $local->color = $colours[$remote->remoteId] ?? $local->color;

                continue;
            }

            if (null !== $local) {
                $this->em->remove($local);
            }
        }

        $this->em->flush();

        foreach ($created as $list) {
            $this->bus->dispatch(new SyncTaskListMessage((int) $list->id));
        }
    }

    private function mirror(User $user, CalendarSource $source, RemoteTaskList $remote): TaskList
    {
        $list              = new TaskList();
        $list->usr         = $user;
        $list->account     = $source->account;
        $list->integration = $source->integration;
        $list->remoteId    = $remote->remoteId;
        $list->name        = mb_substr($remote->name, 0, 120);
        $list->isReadOnly  = $remote->isReadOnly;
        $list->color       = true === in_array($remote->color, Calendar::COLORS, true)
            ? (string) $remote->color
            : ($remote->color ?? TaskList::DEFAULT_COLOR);

        $this->em->persist($list);

        return $list;
    }
}
