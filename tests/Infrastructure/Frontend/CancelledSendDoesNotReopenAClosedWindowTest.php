<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * A cancelled send must not reopen a composer the reader has already closed.
 *
 * THE DEFECT
 *
 * Cancelling a send answers with the composer put back: ComposeController::undo()
 * renders compose/_dock_undo.stream.html.twig or its inline twin, and both are a
 * `<turbo-stream action="replace">` aimed at the frame the window lives in.
 *
 * In the held shape of send (User::SETTING_COMPOSE_SEND_FEEDBACK) the window
 * never leaves the screen while a send is out — that is the whole point of it —
 * so "Save draft and close" sits one click away for every second that request
 * takes. Click it there and the composer closed, and then, when the answer
 * arrived, opened itself again with the draft in it. Nothing on screen
 * explained why; nothing the reader did next made it go.
 *
 * It showed up first as an E2E test that failed only in full-suite runs, where
 * the server is loaded and the gap is wide. It was never a test artefact: the
 * gap is a real interval, and everything in it is a real click.
 *
 * THE FIX, AND WHY IT IS GUARDED HERE
 *
 * compose--compose#_cancelSend asks whether the window it belongs to is still
 * on the page before handing the answer to Turbo, and drops the part of it
 * addressed at that window's own frame when it is not. Four lines of client
 * JavaScript, and this project has no JavaScript test runner — so the choice is
 * between a string comparison and nothing.
 *
 * A string comparison is a crude test. It is also the only kind that would
 * catch the guard being dropped in a refactor, which is exactly how it would
 * come back: the unguarded version reads perfectly well. Same argument as
 * SyncEventsReachTheListTest makes for its Twig attribute, for the same reason.
 *
 * The browser-level proof lives in tests/e2e/compose-undo-reopen.spec.ts, which
 * holds the undo response back and closes the window underneath it.
 */
final class CancelledSendDoesNotReopenAClosedWindowTest extends TestCase
{
    private const string CONTROLLER = 'assets/controllers/compose/compose_controller.js';

    /**
     * The only two templates allowed to put a compose window back through a
     * turbo-stream. Both are answers to a cancel, and the client guards both.
     */
    private const array REOPENING_STREAMS = [
        'templates/compose/_dock_undo.stream.html.twig',
        'templates/compose/_inline_undo.stream.html.twig',
    ];

    public function testTheCancelAsksWhetherItsWindowIsStillOnScreen(): void
    {
        $body = self::methodBody('_cancelSend');

        self::assertStringContainsString(
            '_stillOnScreen()',
            $body,
            '_cancelSend() no longer asks whether the window it belongs to is still on the page. '
            . 'Its answer replaces the whole compose frame, so a reader who closes the composer '
            . 'while the cancel is in flight gets it reopened under their hands.',
        );

        self::assertStringContainsString(
            '_withoutReopen(',
            $body,
            '_cancelSend() no longer strips the reopen out of an answer that arrived after its '
            . 'window was closed. See '.self::CONTROLLER.'.',
        );
    }

    /**
     * The guarded call, not just the guard.
     *
     * An `if` that computes _stillOnScreen() and then renders the response
     * whole either way would pass the test above and fix nothing, so this one
     * asks about the render itself: the text of the response must not reach
     * Turbo without passing through the check.
     */
    public function testNothingHandsTheAnswerStraightToTurbo(): void
    {
        $body = self::methodBody('_cancelSend');

        self::assertMatchesRegularExpression(
            '/Turbo\.renderStreamMessage\(\s*this\._stillOnScreen\(\)/',
            preg_replace('/\s+/', ' ', $body) ?? '',
            'The cancel hands its answer to Turbo without the on-screen check between them. '
            . 'A composer the reader has closed will reopen itself.',
        );
    }

    /**
     * A third way to put a window back would need the same guard.
     *
     * Whoever writes it should meet this test rather than the bug: the two
     * streams named here are guarded in _cancelSend() by name — by the frame
     * they target — and a new one rendered from somewhere else would not be.
     */
    public function testOnlyTheTwoUndoStreamsReopenAComposeWindow(): void
    {
        $root  = dirname(__DIR__, 3);
        $found = [];

        foreach (glob($root.'/templates/compose/*.stream.html.twig') ?: [] as $path) {
            $source = (string) file_get_contents($path);

            if (str_contains($source, 'compose/_window.html.twig')) {
                $found[] = str_replace($root.'/', '', $path);
            }
        }

        sort($found);

        self::assertSame(
            self::REOPENING_STREAMS,
            $found,
            'A stream template renders the compose window that this guard does not know about. '
            . 'Putting a window back is only safe when the reader still has that window: see '
            . 'compose--compose#_cancelSend, and the race it exists for.',
        );
    }

    /** One method out of the controller, from its signature to its closing brace. */
    private static function methodBody(string $name): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/'.self::CONTROLLER);
        $start  = strpos($source, 'async '.$name.'()');

        self::assertIsInt($start, sprintf('%s has no %s().', self::CONTROLLER, $name));

        // Class methods are indented one level, so the first line that is a
        // closing brace at that indentation is the end of this one.
        $end = strpos($source, "\n    }", $start);

        self::assertIsInt($end, sprintf('%s() in %s is never closed.', $name, self::CONTROLLER));

        return substr($source, $start, $end - $start);
    }
}
