<?php

declare(strict_types=1);

namespace App\Domain\Enum\MessageList;

/**
 * Where a list row shows its labels (#608).
 *
 * Line is what the row has always done: in front of the preview on a stacked
 * row, and on a line of their own under a one-line row, because there they
 * would wedge themselves between a subject and a preview already sharing a
 * line. Inline puts them in front of the subject at every width, as Gmail's
 * list does. Dots keeps the colour and drops the word, for somebody who knows
 * their labels by colour and wants the row's width back.
 */
enum LabelDisplay: string
{
    case Inline = 'inline';
    case Line   = 'line';
    case Dots   = 'dots';
    case Hidden = 'hidden';
}
