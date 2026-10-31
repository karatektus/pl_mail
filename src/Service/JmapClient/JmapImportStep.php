<?php

declare(strict_types=1);

namespace App\Service\JmapClient;

/**
 * What one page of a JMAP account's first import left to do.
 *
 * Two cases where GraphImportStep has three: a JMAP listing is one query over
 * the whole account rather than a folder at a time, so there is no folder to
 * put to the back of the order and nothing to wait on.
 */
enum JmapImportStep
{
    /** There is more to read. Ask for it. */
    case More;

    /** Every Email has been listed, and changes are read from the baseline. */
    case Finished;
}
