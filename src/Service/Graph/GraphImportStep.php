<?php

declare(strict_types=1);

namespace App\Service\Graph;

/**
 * What one page of a Microsoft folder's first enumeration left to do.
 */
enum GraphImportStep
{
    /** There is more to read, in this folder or the next. Ask for it. */
    case More;

    /**
     * The folder whose turn it was could not be read just now. It has gone to
     * the back of the order; asking again at once would only ask the next one
     * the same question a second sooner than it needs to be.
     */
    case Waiting;

    /** Every folder has been read to its end and is followed by its delta link. */
    case Finished;
}
