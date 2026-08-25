<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Demo\DemoMode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The routes a demo visitor may not use.
 *
 * A short list on purpose. The instinct with a public demo is to lock
 * everything that is not mail, and that instinct produces a demo where the 41
 * themes cannot be tried and the filter builder cannot be opened — a tour of a
 * product with the interesting doors locked. Everything here stays clickable
 * except the things that would attach this install to somebody's real mailbox.
 *
 * That is the actual hazard, and it is not primarily ours. A stranger who
 * types their Gmail address and app password into a demo box on a server they
 * do not control has handed their mail to whoever runs it — and the form would
 * have worked, because attaching accounts is the product. Refusing is the only
 * honest answer; there is no version of that form that is safe to show here.
 *
 * The OAuth connect routes are on the list for the same reason and one more:
 * they bounce through a real provider, so leaving them open would put this
 * install's client id in front of an authorisation screen naming a demo nobody
 * vetted.
 *
 * Note this is a backstop, not the only defence. Sending is neutered at the
 * sender registry, sync at the commands, and the reaper deletes what a visitor
 * leaves behind — a URL blocked here is a URL that would otherwise render a
 * form, not one that would otherwise reach the network.
 */
final readonly class DemoGuardSubscriber implements EventSubscriberInterface
{
    /**
     * Matched as prefixes against the route name, so a route added inside one
     * of these controllers later is covered by default rather than by
     * remembering to come back here. `app_account_` is the whole of mail
     * account management — new, edit, delete, toggle, reorder, test-connection
     * — and all of it is either attaching a real mailbox or probing the
     * network on behalf of one.
     */
    private const array BLOCKED_PREFIXES = [
        'app_account_',
        'app_oauth_connect',
        'app_integration_oauth_connect',
        'app_settings_calendar_caldav_connect',
        'app_settings_calendar_subscribe',
    ];

    public function __construct(
        private DemoMode              $demoMode,
        private RequestStack          $requestStack,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface   $translator,
        private Environment           $twig,
    ) {
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        // After the firewall, so the redirect target is a page the visitor can
        // actually see, and so an anonymous caller gets the login page rather
        // than this.
        return [RequestEvent::class => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        if (false === $this->demoMode->isEnabled()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (false === is_string($route) || false === $this->isBlocked($route)) {
            return;
        }

        // Most of these routes are opened INSIDE the settings modal, which is a
        // turbo-frame. A redirect is useless there: Turbo looks for
        // <turbo-frame id="modal"> in the response, the settings page has none,
        // and the frame is left exactly as it was — showing its loading
        // spinner, forever. The visitor gets a modal that spins instead of one
        // that says why the form is not coming.
        $frame = $event->getRequest()->headers->get('Turbo-Frame');

        if (null !== $frame && '' !== $frame) {
            $event->setResponse(new Response($this->twig->render('demo/_unavailable.html.twig', [
                'frame' => $frame,
            ])));

            return;
        }

        $session = $this->requestStack->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            // 'info' rather than 'warning': the layout's toast region reads
            // success, error and info only, and a flash of any other type is
            // silently never shown.
            $session->getFlashBag()->add('info', $this->translator->trans('demo.flash.blocked'));
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_settings_index')));
    }

    private function isBlocked(string $route): bool
    {
        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
