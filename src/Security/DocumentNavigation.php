<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Whether a request is somebody going to a page, as opposed to a page they
 * were already looking at fetching something.
 *
 * WHY IT MATTERS: "where were you going?" is remembered when an unauthenticated
 * request is turned away, so that signing in can send you there. Symfony's
 * test for what is worth remembering is `GET and not XMLHttpRequest`, and so
 * is the two-factor bundle's. An image passes that. So does a `fetch()`, a
 * lazily loaded Turbo Frame, and the call that refreshes the live-update
 * cookie. Whichever of them was turned away last decided where signing in
 * sent you — to a picture, to a fragment of a page, or to an endpoint that
 * answers 204, where the browser stays on the form it just submitted and the
 * button says "Checking…" for ever.
 *
 * TWO PLACES REMEMBER, and they have to agree, which is why this is a class
 * and not a private method of either: LoginFormAuthenticator before anybody is
 * signed in, and TwoFactorRequired between the password and the code. The
 * second was written later, had no test of its own, and was where the last of
 * these reports came from.
 *
 * The test is deliberately two-sided rather than a list of file extensions.
 * `Sec-Fetch-Dest: document` is what a browser says about a real navigation,
 * and every browser that has shipped in years sends it; an Accept header
 * mentioning text/html covers a Turbo visit (which is a fetch, and says
 * `Sec-Fetch-Dest: empty`) and anything older that sends no Sec-Fetch headers
 * at all. An image, a stylesheet, a script or a JSON fetch matches neither.
 *
 * A Turbo FRAME is refused before either, because it would pass the second: it
 * asks for text/html, and what it gets is a fragment that is not a page.
 */
final class DocumentNavigation
{
    public static function is(Request $request): bool
    {
        if (true === $request->headers->has('Turbo-Frame')) {
            return false;
        }

        if ('document' === $request->headers->get('Sec-Fetch-Dest')) {
            return true;
        }

        return str_contains((string) $request->headers->get('Accept'), 'text/html');
    }
}
