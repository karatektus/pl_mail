<?php

declare(strict_types=1);

namespace App\Domain\Enum\Layout;

/**
 * Which way a split divides its box (#755): its children side by side, or one
 * above the other. The values are the flex directions the stylesheet draws
 * them with, abbreviated the way the stored layout spells them.
 */
enum SplitDirection: string
{
    /** Side by side: the children share the width. */
    case Row = 'row';

    /** Above and below: the children share the height. */
    case Column = 'col';
}
