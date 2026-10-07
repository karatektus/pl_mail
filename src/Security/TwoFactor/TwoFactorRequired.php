<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Security\DocumentNavigation;
use Scheb\TwoFactorBundle\Security\Http\Authentication\AuthenticationRequiredHandlerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\TwoFactorFirewallConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\HttpUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Sends somebody who has given a password but not yet a code to the code form
 * — and remembers where they were going only if they were going somewhere.
 *
 * The bundle's own handler, with one condition added. Its test for what to
 * remember is `GET and not XMLHttpRequest`, and the code form is a page like
 * any other: it carries the layout, and the layout fetches things. Every one
 * of those fetches is turned away here while the code is outstanding, and the
 * bundle remembered each as the destination, the last one winning.
 *
 * REPORTED AS THE CODE FORM HANGING. A correct code was accepted, the redirect
 * went to the remembered URL, and that URL was the live-update cookie refresh,
 * which answers 204 — so the browser, correctly, stayed where it was, on a
 * form whose button now read "Checking…". Reloading showed the inbox, because
 * the sign-in had in fact succeeded. It showed up on an installation whose
 * live updates were failing, since a failing connection is what makes the page
 * keep refreshing that cookie.
 *
 * See DocumentNavigation for the test, which LoginFormAuthenticator shares.
 */
final readonly class TwoFactorRequired implements AuthenticationRequiredHandlerInterface
{
    use TargetPathTrait;

    public function __construct(
        private HttpUtils $httpUtils,
        #[Autowire(service: 'security.firewall_config.two_factor.main')]
        private TwoFactorFirewallConfig $config,
    ) {
    }

    public function onAuthenticationRequired(Request $request, TokenInterface $token): Response
    {
        // The check path is excluded for the bundle's own reason: there it is
        // one more redirect in a multi-factor sequence, not a destination.
        if (false === $this->config->isCheckPathRequest($request)
            && true === $request->hasSession()
            && true === $request->isMethodSafe()
            && true === DocumentNavigation::is($request)) {
            $this->saveTargetPath($request->getSession(), $this->config->getFirewallName(), $request->getUri());
        }

        return $this->httpUtils->createRedirectResponse($request, $this->config->getAuthFormPath());
    }
}
