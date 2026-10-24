<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Keeps somebody whose password was chosen for them on the page where they
 * choose their own, until they have.
 *
 * See User::$passwordChangeRequired for who that is and why it matters. The
 * flag is only worth anything if nothing else is reachable while it is set —
 * a prompt that could be dismissed would be clicked past by everyone, which
 * is the reason this is a listener and not a banner.
 *
 * Only the `main` firewall. JMAP and CalDAV clients authenticate with app
 * passwords, which are their own credentials and are not what the flag is
 * about: `app:user:password` sets it on an account that may well have a phone
 * syncing, and that phone must keep syncing while its owner gets round to the
 * browser.
 *
 * A page navigation is sent to the form; anything else the page fetches gets a
 * bare 403. A redirect would answer an image or a live-update refresh with the
 * form's HTML, which helps nobody — see DocumentNavigation for how the two are
 * told apart.
 */
final readonly class PasswordChangeRequiredListener implements EventSubscriberInterface
{
    /**
     * Where the person may still go: the form itself, and the ways out of it.
     */
    private const array ALLOWED_ROUTES = [
        'app_password_required',
        'app_logout',
        'app_login',
        'app_login_prelogin',
    ];

    public function __construct(
        private Security              $security,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        // After the firewall (8), which is what puts a user on the token.
        return [RequestEvent::class => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ('main' !== $this->security->getFirewallConfig($request)?->getName()) {
            return;
        }

        $token = $this->security->getToken();

        // Halfway through a sign-in: the code form comes first, and the
        // two-factor bundle keeps everything else shut until it is answered.
        if (null === $token || $token instanceof TwoFactorTokenInterface) {
            return;
        }

        $user = $token->getUser();

        if (false === $user instanceof User || false === $user->passwordChangeRequired) {
            return;
        }

        if (true === in_array($request->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }

        $event->setResponse(
            true === DocumentNavigation::is($request)
                ? new RedirectResponse($this->urls->generate('app_password_required'))
                : new Response('', Response::HTTP_FORBIDDEN),
        );
    }
}
