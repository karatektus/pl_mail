<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Ai;

use App\Domain\DTO\Ai\SemanticSearchReport;
use App\Domain\Enum\Ai\SemanticSkipReason;
use PHPUnit\Framework\TestCase;

/**
 * The four things the search page can say about the meaning pass, and the pairs
 * of them that must never be confused.
 *
 * "Still working on it" and "there was nothing extra to find" produce the same
 * list of results and mean opposite things. Told the second when the first is
 * true, somebody concludes the feature does not work on their mail and stops
 * using it — while the backfill that would have proved otherwise is running at
 * eight per cent behind them.
 */
final class SemanticSearchReportTest extends TestCase
{
    /** A pass nobody switched on says nothing at all. */
    public function testASwitchedOffFeatureIsSilent(): void
    {
        $report = new SemanticSearchReport(SemanticSkipReason::FeatureOff, 0, 0, 0, 0);

        self::assertFalse($report->ran());
        self::assertFalse($report->speaks());
    }

    /** Every other reason is worth a sentence, because somebody said yes to this. */
    public function testAFeatureThatWasSwitchedOnAndCouldNotRunSaysSo(): void
    {
        foreach ([
            SemanticSkipReason::NotConfigured,
            SemanticSkipReason::HostUnreachable,
            SemanticSkipReason::TimedOut,
            SemanticSkipReason::ModelMissing,
            SemanticSkipReason::ModelAnsweredBadly,
            SemanticSkipReason::QueryTooShort,
        ] as $reason) {
            self::assertTrue(
                (new SemanticSearchReport($reason, 0, 0, 0, 0))->speaks(),
                $reason->value . ' has to reach the person searching',
            );
        }
    }

    public function testAnIncompleteIndexIsReportedAsProgressRatherThanAsAResult(): void
    {
        $report = new SemanticSearchReport(null, 4_120, 48_900, 0, 0);

        self::assertTrue($report->indexing());
        self::assertFalse($report->foundNothingExtra(), 'nothing extra YET is not nothing extra');
        self::assertSame(8, $report->percent());
    }

    /**
     * The notice goes away before the last message is embedded.
     *
     * A mailbox holds messages that will never produce a vector, so demanding
     * the final one would leave a progress line under every search forever.
     */
    public function testAnAlmostFinishedIndexStopsTalkingAboutItself(): void
    {
        $report = new SemanticSearchReport(null, 49_500, 49_900, 0, 0);

        self::assertTrue($report->complete());
        self::assertFalse($report->indexing());
    }

    public function testAFinishedIndexThatAddedNothingSaysThatInstead(): void
    {
        $report = new SemanticSearchReport(null, 100, 100, 0, 0);

        self::assertTrue($report->foundNothingExtra());
        self::assertFalse($report->indexing());
        self::assertTrue($report->speaks());
    }

    /** A pass that helped needs no footnote: the rows it found carry their own. */
    public function testAFinishedIndexThatFoundSomethingIsQuiet(): void
    {
        self::assertFalse((new SemanticSearchReport(null, 100, 100, 0, 3))->speaks());
    }

    /**
     * Everything indexed, none of it by the model search is using.
     *
     * The same 0 as "the backfill has not started", and the opposite advice:
     * waiting fixes one of them and only a re-index fixes the other.
     */
    public function testAChangedModelIsNotAnUnstartedBackfill(): void
    {
        $changed = new SemanticSearchReport(null, 0, 48_900, 48_900, 0);

        self::assertTrue($changed->modelChanged());
        self::assertFalse($changed->indexing(), 'the progress line would tell somebody to wait for nothing');

        $unstarted = new SemanticSearchReport(null, 0, 48_900, 0, 0);

        self::assertFalse($unstarted->modelChanged());
        self::assertTrue($unstarted->indexing());
    }

    /** An empty mailbox is not eight per cent of anything. */
    public function testAnEmptyMailboxDoesNotReportProgress(): void
    {
        $report = new SemanticSearchReport(null, 0, 0, 0, 0);

        self::assertSame(100, $report->percent());
        self::assertFalse($report->indexing());
    }
}
