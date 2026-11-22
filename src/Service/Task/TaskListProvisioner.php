<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Entity\Calendar\Calendar;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Repository\Task\TaskListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Makes sure a user has a list for a task to land in.
 *
 * On first need rather than on user creation or in a migration. Nobody who never
 * opens the pane needs a row, and "every user has one" kept true by a backfill
 * would have to be kept true again by every path that creates a user.
 *
 * Does not flush; it joins the caller's unit of work, as CalendarProvisioner does.
 */
final readonly class TaskListProvisioner
{
    public function __construct(
        private TaskListRepository     $lists,
        private EntityManagerInterface $em,
        private TranslatorInterface    $translator,
    ) {}

    /**
     * The name is seed data, written once in the user's own locale and theirs to
     * rename from then on — CalendarProvisioner's reasoning.
     */
    public function defaultFor(User $user): TaskList
    {
        $existing = $this->lists->findDefaultForUser($user);

        if (null !== $existing) {
            return $existing;
        }

        $list            = new TaskList();
        $list->usr       = $user;
        $list->name      = $this->translator->trans('tasks.default_list', [], null, $user->locale);
        $list->isDefault = true;
        $list->color     = Calendar::COLORS[0];

        $this->em->persist($list);

        return $list;
    }
}
