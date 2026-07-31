<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\User\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Re-issues the Mercure subscriber cookie for the signed-in user.
 *
 * The cookie carries a JWT with an expiry — an hour by default, and in any case
 * shorter than a session with remember-me on it. The layout mints one when a
 * page renders, which is fine until a tab is left open: the hub validates the
 * token only when a connection is established, so an open stream survives the
 * expiry and the *reconnect* after it is refused with a 401. EventSource treats
 * that as fatal and stops for good, which is the failure this endpoint exists to
 * prevent — the client refreshes the cookie here before each retry, so a tab
 * open for a day recovers exactly like one open for a minute.
 *
 * Returns no body. The cookie is the entire payload, and the JWT inside it is a
 * credential, so it stays in a Set-Cookie header rather than anywhere a script
 * or a log could read it back.
 */
final class MercureAuthController extends AbstractController
{
    #[Route('/mercure/auth', name: 'mercure_auth', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(
        Request $request,
        Authorization $authorization,
        #[CurrentUser] User $user,
    ): Response
    {
        // The same topic the layout subscribes to. A user may only ever be
        // authorized for their own: the id comes from the session, never from
        // the request, so a caller cannot ask for somebody else's stream.
        $topics = ['mail/user/'.$user->getId()];

        // 204 rather than 200: there is no representation to return, and the
        // client only cares that the Set-Cookie arrived.
        $response = new Response(null, Response::HTTP_NO_CONTENT);

        // Never cached. A cached 204 would leave the browser reusing a response
        // whose Set-Cookie has long since expired, which is the exact state
        // this endpoint exists to get out of.
        $response->headers->set('Cache-Control', 'no-store, private');

        // Same guard as MercureCookieSubscriber, for the same reason: a hub on
        // another second-level domain cannot be given a first-party cookie, and
        // that is a deployment choice rather than a fault. Answering 204 with no
        // cookie lets the client retry and report itself offline, which is the
        // truth; a 500 here would instead turn the reconnect loop into a stream
        // of server errors in the log.
        try {
            $response->headers->setCookie($authorization->createCookie($request, $topics));
        } catch (\Throwable) {
            // Deliberately quiet: the subscriber above already logs this once
            // per response, and this endpoint is called on every retry.
        }

        return $response;
    }
}
