<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * Rejects a state-changing request that cannot prove it came from our own page.
 *
 * Extracted after the eleventh copy. Every controller that mutates something
 * had grown its own three-line version of this under one of two names —
 * `validateCsrf()` and `assertCsrf()` — and they had already drifted: some read
 * `_token` with an empty-string default and some without, some passed a message
 * to the exception and some did not, and one baked its token prefix into the
 * helper so the call sites read `validateCsrf($request, $id)` for a token
 * actually called `admin_failed_$id`. None of that was a bug on its own. The
 * point of one copy is that the next divergence cannot be a bug either.
 *
 * Both transports are accepted, because the app genuinely uses both and the
 * choice is the frontend's, not the endpoint's: Twig forms post `_token` in the
 * body, while the Stimulus controllers `fetch()` a JSON body and put the token
 * in `X-CSRF-Token` from the `csrf-token` meta tag. Taking either means an
 * action does not have to care which kind of caller it grew, and moving a form
 * to fetch() never silently turns the check off. Accepting the header is not
 * the weaker half: a cross-origin caller can set neither, and it cannot read
 * the token to forge either one.
 *
 * Token ids stay per-action and per-subject at the call site rather than a
 * single shared `ajax` id — see AccountHealthController, where the reasoning is
 * that one token good for every action makes any one XSS worth all of them.
 *
 * A trait rather than a base controller, for the same reason as
 * {@see RendersTurboStreams}: these controllers already extend Symfony's
 * AbstractController.
 */
trait ChecksCsrf
{
    /**
     * @param string $tokenId the id the matching form or fetch() minted the token under
     * @param string $field   the body field to read, for the one form that carries two
     *                        tokens — CalendarController's delete button posts
     *                        `_deleteToken` from inside a form that already has a
     *                        `_token` for editing
     */
    private function assertCsrf(Request $request, string $tokenId, string $field = '_token'): void
    {
        $token = (string) ($request->request->get($field) ?? $request->headers->get('X-CSRF-Token') ?? '');

        if (false === $this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
