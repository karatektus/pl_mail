<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Throwable;

/**
 * The code form arriving at a session that has already finished with it.
 *
 * Submit the six digits twice — a double click, a second press of Enter, the
 * browser's own "resend?" after a back — and the two POSTs race. The first
 * completes the second factor and replaces the half-authenticated token with a
 * full one; the second reaches
 * {@see \Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator},
 * finds no TwoFactorToken in storage and throws "User is not in a two-factor
 * authentication process." Symfony's firewall turns that into a 403, and
 * because the person is by then signed in there is no entry point to send them
 * to, so they get the bare error page — a debug stack trace on a dev install —
 * at the exact moment they successfully logged in. The same happens on a plain
 * back-button to /2fa, which scheb's FormController refuses for the same reason.
 *
 * The refusal is right and stays: /2fa and /2fa_check are for a login in
 * progress and nothing else. What is wrong is the answer given to the one
 * caller who is not doing anything suspicious — a browser holding a session
 * that already cleared both factors. It asked to finish logging in, and it is
 * logged in. So it gets sent where the login was going.
 *
 * Narrow deliberately, because this softens an access denial:
 *
 *   - only the two 2FA routes, so no other AccessDeniedException is touched;
 *   - only when the token is NOT a TwoFactorToken, so a login genuinely in
 *     progress still gets scheb's own handling (its ExceptionListener, at
 *     priority 2, is what redirects that case back to the form);
 *   - only when the session already holds ROLE_USER — the same gate the rest of
 *     the app is behind. A caller with no session, a half-authenticated one, or
 *     one whose second factor never completed still gets today's refusal, and
 *     learns nothing it could not learn by asking for the inbox.
 *
 * Runs at priority 3, ahead of both scheb's ExceptionListener (2) and the
 * firewall's own (1), which is the only ordering in which the response is still
 * this listener's to set.
 */
final readonly class CompletedTwoFactorRedirect
{
    use TargetPathTrait;

    /** The firewall the 2FA routes live behind — see config/packages/security.yaml. */
    private const string FIREWALL = 'main';

    /** @var list<string> */
    private const array ROUTES = ['app_2fa_login', 'app_2fa_login_check'];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsEventListener(event: ExceptionEvent::class, priority: 3)]
    public function onException(ExceptionEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (false === in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        if (false === $this->isAccessDenied($event->getThrowable())) {
            return;
        }

        // A login still in progress is scheb's business, not this listener's.
        if ($this->security->getToken() instanceof TwoFactorTokenInterface) {
            return;
        }

        if (false === $this->security->isGranted('ROLE_USER')) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->destination($event)));
    }

    /**
     * Where the login was going.
     *
     * The saved target path if one is still there, and the front page
     * otherwise — the same fallback
     * {@see \App\Security\LoginFormAuthenticator::onAuthenticationSuccess()}
     * uses, so a resubmit lands exactly where the submit that beat it did.
     * Usually it is the fallback: the request that won consumed the target path
     * on its way through.
     */
    private function destination(ExceptionEvent $event): string
    {
        $request = $event->getRequest();
        $session = true === $request->hasSession() ? $request->getSession() : null;

        $target = null !== $session ? $this->getTargetPath($session, self::FIREWALL) : null;

        return null !== $target && '' !== $target
            ? $target
            : $this->urlGenerator->generate('app_default_index');
    }

    /**
     * Unwrap, because the firewall hands the denial on wrapped in whatever the
     * kernel caught — the same walk scheb's own ExceptionListener does.
     */
    private function isAccessDenied(Throwable $throwable): bool
    {
        for ($exception = $throwable; null !== $exception; $exception = $exception->getPrevious()) {
            if ($exception instanceof AccessDeniedException) {
                return true;
            }
        }

        return false;
    }
}
