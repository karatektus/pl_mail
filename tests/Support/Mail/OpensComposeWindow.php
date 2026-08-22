<?php

declare(strict_types=1);

namespace App\Tests\Support\Mail;

/**
 * How the compose window is actually requested.
 *
 * The window is a fragment — no `<html>`, no stylesheet, no JavaScript, because
 * it is built to land inside the dock's turbo-frame, which already has all
 * three. Requesting it without the frame header is therefore not a smaller
 * version of the real request; it is a different one, and it now answers a
 * redirect to the mailbox rather than the fragment. That is the fix for a
 * bookmarked compose URL rendering as raw HTML.
 *
 * These tests are about the fragment, so they ask for it the way the dock does.
 * The constant exists rather than the header being repeated fourteen times
 * because the next test to open a window should not have to discover this by
 * watching a redirect arrive.
 */
trait OpensComposeWindow
{
    /**
     * The dock's own request headers.
     *
     * `HTTP_TURBO_FRAME` is how Symfony's test client spells the `Turbo-Frame`
     * header a real frame sends. The value is the dock's id, which is also the
     * default ComposeContext falls back to.
     *
     * @var array<string, string>
     */
    private const array DOCK_FRAME = ['HTTP_TURBO_FRAME' => 'compose_dock'];
}
