<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Entity\User\User;
use App\Service\User\UserTimezoneResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;
use Twig\Extension\CoreExtension;

/**
 * Render every `|date` in the user's own timezone.
 *
 * Twig's date filter formats in whatever CoreExtension holds, and with nothing
 * configured that is PHP's default — UTC in this container. Since instants are
 * stored in UTC, formatting them in UTC produced strings that were correct and
 * useless: an 11:00 Berlin meeting shipped to the browser as the literal text
 * "09:00", with no offset left in it for anything downstream to fix.
 *
 * Set centrally rather than by passing a zone to each `|date` call: there are
 * dozens of them across mail, calendar, settings and admin, and the failure
 * mode of missing one is a single date that is quietly two hours out. One
 * template author forgetting is not a risk worth carrying.
 *
 * Runs alongside UserLocaleSubscriber at the same priority and for the same
 * reason — after the firewall, so there is a token to read.
 */
final readonly class TwigTimezoneSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private Environment $twig,
        private UserTimezoneResolver $timezones,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Sub-requests inherit the main request's user; re-resolving on each
        // would be work for the same answer, and a fragment rendered for a
        // different token is not a thing this app does.
        if (false === $event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        // Set unconditionally, including for anonymous requests. The Twig
        // environment is a shared service and outlives a request under a
        // worker runtime, so returning early here would leave the previous
        // signed-in user's zone in place for whoever asks next.
        $this->twig->getExtension(CoreExtension::class)->setTimezone(
            $this->timezones->resolve($user instanceof User ? $user : null),
        );
    }
}
