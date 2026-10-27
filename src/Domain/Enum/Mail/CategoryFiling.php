<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * What became of a filter's "File in category" action on one message.
 *
 * An enum rather than a bool because there were two ways of not filing until
 * #405 took the sender check out of filing, and "Test my filters" names the
 * outcome by this case.
 *
 * See App\Service\Rule\RuleCategoryFiler.
 */
enum CategoryFiling
{
    /** The message is under the reader's category now. */
    case Filed;

    /** No category to file under: none named, deleted, or not the rule owner's. */
    case NoCategory;
}
