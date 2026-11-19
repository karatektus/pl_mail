<?php

declare(strict_types=1);

namespace App\Domain\Enum\MessageList;

/**
 * Whether the star of a conversation that is not starred is always there or
 * appears under the pointer (#608).
 *
 * Hover is what the list has always done, so a list of fifty rows is not fifty
 * outlined stars. The row's quick actions (archive, delete, spam, snooze,
 * read) had the same choice while #608 was built and lost it: shown on every
 * row they crowded the subject out on any list short of very wide, and pushed
 * the date aside on a stacked one. They stay on hover.
 */
enum RowReveal: string
{
    case Hover  = 'hover';
    case Always = 'always';
}
