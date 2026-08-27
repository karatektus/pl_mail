<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * A cancel pressed before the send could be cancelled still calls it off.
 *
 * THE DEFECT
 *
 * Pressing Send queues the real send on a worker behind
 * ComposeController::SEND_DELAY_MS (ten seconds) and offers the reader
 * CANCEL_WINDOW_MS (eight) to call it back. The undo URL — the only way to do
 * the calling back — does not exist until the server has answered with the
 * message's id, so a cancel pressed before that answer is recorded as standing
 * and honoured by armSendHold() when the arming markup arrives.
 *
 * That is sound until the reader ALSO closes the window in the same gap. The
 * arming markup then connects into an emptied frame, finds no
 * `compose--compose` root to hand the cancel to, and returns — and the standing
 * flag dies with the controller that held it. Nothing reaches the server, and
 * ten seconds later the worker sends a message somebody had cancelled.
 *
 * The failure is silent in every direction: no exception, no console line, no
 * log entry, and an interface that showed the cancel being accepted. From the
 * code's point of view nothing went wrong.
 *
 * WHY THE FLAG CANNOT LIVE ON THE CONTROLLER
 *
 * The controller is the thing that goes away. The fact has to outlive it, which
 * is what assets/compose/pending_cancel.js is for — module scope, so it lasts
 * as long as the page and no longer.
 *
 * KEYED BY FRAME, and that is not decoration. The dock and an inline reply can
 * each hold a send at once; a bare flag would let a cancel in one call off the
 * other, which destroys mail somebody meant to send — a worse bug than the one
 * being fixed.
 *
 * WHY THIS IS A STRING TEST
 *
 * There is no JavaScript test runner in this repository, and the ordering it
 * describes needs a real worker, a real delay and a closed window to reproduce.
 * The same argument SyncEventsReachTheListTest and
 * CancelledSendDoesNotReopenAClosedWindowTest make: crude beats absent when the
 * thing being protected is a silent loss.
 */
final class AStandingCancelSurvivesAClosedWindowTest extends TestCase
{
    private const string REGISTRY = 'assets/compose/pending_cancel.js';
    private const string COMPOSER = 'assets/controllers/compose/compose_controller.js';
    private const string HOLD     = 'assets/controllers/compose/send_hold_controller.js';

    /** The press is recorded somewhere that outlives the composer. */
    public function testTheComposerRecordsAStandingCancelOutsideItself(): void
    {
        $composer = $this->read(self::COMPOSER);

        self::assertStringContainsString(
            'markPendingCancel(this.#frameId)',
            $composer,
            'a cancel pressed before the undo URL exists must be recorded outside this controller',
        );

        // Immediately after the flag it sets on itself, so the two cannot drift
        // apart into "recorded here but not there".
        self::assertMatchesRegularExpression(
            '/_cancelRequested\s*=\s*true;.*?markPendingCancel/s',
            $composer,
            'the outside record must be taken at the same moment as the internal flag',
        );
    }

    /** And given up again the moment this composer can honour it itself. */
    public function testArmingTheHoldGivesTheOutsideRecordBack(): void
    {
        self::assertStringContainsString(
            'forgetPendingCancel(this.#frameId)',
            $this->read(self::COMPOSER),
            'a live composer owns the cancel, and a copy left behind would fire on the next send',
        );
    }

    /**
     * The one that matters: no composer left, so the arming element spends it.
     *
     * Asserted on the early return itself rather than anywhere in the file — an
     * undo posted somewhere else in that controller would not be reached at all
     * on the path this is about, which is the path that returns.
     */
    public function testTheArmingElementCallsOffASendWithNoComposerLeft(): void
    {
        $hold = $this->read(self::HOLD);

        self::assertMatchesRegularExpression(
            '/null === root\)\s*\{.*?takePendingCancel.*?fetch\(\s*this\.undoUrlValue.*?return;/s',
            $hold,
            'with no composer to hand the cancel to, this element has to send it: '
            . 'it is the only thing that ever learns the undo URL',
        );

        self::assertStringContainsString(
            'keepalive: true',
            $hold,
            'the window is gone and the reader may be navigating away — a cancel lost to an '
            . 'unload is the same lost cancel by another route',
        );
    }

    /** Spent once, or the next send from that frame cancels itself. */
    public function testAStandingCancelIsSpentRatherThanRead(): void
    {
        $registry = $this->read(self::REGISTRY);

        self::assertMatchesRegularExpression(
            '/function takePendingCancel.*?return pending\.delete/s',
            $registry,
            'taking a standing cancel must remove it',
        );

        self::assertStringContainsString(
            'const pending = new Set()',
            $registry,
            'keyed per frame: the dock and an inline reply can each hold a send at once',
        );
    }

    private function read(string $relative): string
    {
        $path = \dirname(__DIR__, 3) . '/' . $relative;

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
