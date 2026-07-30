<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Entity\User\User;
use App\Domain\Enum\AppLocale;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
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
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
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

        $locale = AppLocale::tryFromRequest($user->getLocale());

        if (null === $locale) {
            return;
        }

        $event->getRequest()->setLocale($locale->value);
        $this->translator->setLocale($locale->value);
    }
}
