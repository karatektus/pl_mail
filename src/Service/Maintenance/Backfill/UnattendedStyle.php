<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Backfill;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SymfonyStyle for a backfill nobody is sitting in front of.
 *
 * Two tasks ask before they act: `rethread` ("Rebuild all threads now?") and
 * `category` ("Re-run messages that already have a category?"). On a worker
 * there is nobody to answer, and an unanswered confirm() returns its default —
 * no, in both — so `rethread` started from the page would print its warning
 * and stop without doing anything, and say it succeeded.
 *
 * Here the answer is yes. The question was asked on the page instead: its
 * explanation of both tasks says what they rebuild, and pressing Run after
 * reading it is the yes. The question and the answer are printed, so the
 * stored output shows what was decided and by what.
 */
final class UnattendedStyle extends SymfonyStyle
{
    public function confirm(string $question, bool $default = true): bool
    {
        $this->writeln(sprintf('%s yes (Run pressed on Admin → Backfills)', $question));

        return true;
    }
}
