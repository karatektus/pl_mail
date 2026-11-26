<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Security\LocalRedirect;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Where a correct code sends you: scheb's own handler, answering with a path.
 *
 * The bundle's handler redirects through HttpUtils, which makes even its "/"
 * fallback absolute from the request's host — the container's internal IP
 * behind a proxy that forwards the upstream address (#669). Otherwise the
 * same: the saved target if there is one, consumed, else the front page.
 */
final readonly class TwoFactorSucceeded implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    /** The firewall the 2FA routes live behind — see config/packages/security.yaml. */
    private const string FIREWALL = 'main';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $session = $request->getSession();
        $session->remove(SecurityRequestAttributes::AUTHENTICATION_ERROR);

        $target = $this->getTargetPath($session, self::FIREWALL);
        $this->removeTargetPath($session, self::FIREWALL);

        return new RedirectResponse(LocalRedirect::path($target, $this->urlGenerator->generate('app_default_index')));
    }
}
