<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Entity\User\User;
use App\Domain\Enum\AppLocale;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use App\Infrastructure\Event\Subscriber\AppearanceCookieSubscriber;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Render the interface in the locale the signed-in user picked.
 *
 * Runs after the firewall (priority 8) so a token is available, and pushes the
 * locale into the translator by hand: Symfony's own LocaleListener already ran
 * at priority 16 with the default locale, so changing the request locale here
 * would otherwise leave the translator behind.
 */
final class UserLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LocaleAwareInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onKernelRequest', 6],
            // Before Symfony's own ErrorListener (-128), so the error page is
            // rendered in the right language rather than corrected afterwards.
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (false === $user instanceof User) {
            return;
        }

        $locale = AppLocale::tryFromRequest($user->locale);

        if (null === $locale) {
            return;
        }

        $event->getRequest()->setLocale($locale->value);
        $this->translator->setLocale($locale->value);
    }

    /**
     * The same job for the pages nobody authenticated for.
     *
     * A 404 is thrown by the router at priority 32 of kernel.request — before
     * the firewall at 8 and before onKernelRequest above at 6. Neither has run
     * when the exception is handled, so the token storage is empty and the
     * request still carries the default locale: a German user mistyping a URL
     * got an English page, in the default theme, which reads as a different
     * application.
     *
     * The user cannot be looked up here either, for the same reason the error
     * template refuses to: whatever threw might be the session, the database or
     * the firewall itself, and an error handler that throws leaves the visitor
     * on Symfony's bare fallback. So the locale comes from the cookie written
     * on ordinary responses — see AppearanceCookieSubscriber, which carries the
     * theme in the same value for the same reason.
     *
     * A no-op when the token IS available, which is every exception that is not
     * a routing failure: those have been through onKernelRequest already and
     * the locale is right.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (null !== $this->tokenStorage->getToken()?->getUser()) {
            return;
        }

        [, $stored] = AppearanceCookieSubscriber::read(
            $request->cookies->get(AppearanceCookieSubscriber::COOKIE),
        );

        $locale = AppLocale::tryFromRequest($stored);

        if (null === $locale) {
            return;
        }

        $request->setLocale($locale->value);
        $this->translator->setLocale($locale->value);
    }
}
