<?php

declare(strict_types=1);

namespace App\Domain\Enum\Task;

/**
 * What the pane is looking at: the automatic day, or the lists.
 *
 * My Day is a view and nothing else — no column, no membership. A task is on it
 * because of its dates, so nothing has to keep a second copy of "today" true at
 * midnight, and a sync that moves a due date moves it on or off without knowing
 * this view exists.
 */
enum TaskView: string
{
    case MyDay = 'my-day';
    case Lists = 'lists';
}
