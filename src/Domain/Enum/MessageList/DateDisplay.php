<?php

declare(strict_types=1);

namespace App\Domain\Enum\MessageList;

/**
 * How a list row dates a conversation (#608).
 *
 * Smart is what the row has always done: the time today, the day and month
 * this year, the full date before that. Full is the day and the time on every
 * row, for somebody who reads a list by when things arrived rather than by
 * roughly how old they are.
 */
enum DateDisplay: string
{
    case Smart = 'smart';
    case Full  = 'full';
}
