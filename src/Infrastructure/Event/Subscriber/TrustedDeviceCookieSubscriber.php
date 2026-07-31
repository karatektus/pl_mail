<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Security\TwoFactor\TrustedDeviceCookieJar;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes whatever the trusted-device cookie jar decided during authentication
 * onto the response on its way out.
 *
 * The counterpart to TrustedDeviceCookieJar: the decision is made deep inside
 * the security layer, where there is no Response, and this is the first place
 * afterwards that has one.
 */
final readonly class TrustedDeviceCookieSubscriber implements EventSubscriberInterface
{
    public function __construct(private TrustedDeviceCookieJar $cookies)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Sub-requests share the jar with the master request; letting one take
        // the pending cookie would write it onto a response that is discarded.
        if (false === $event->isMainRequest()) {
            return;
        }

        $cookie = $this->cookies->takePending();

        if (null !== $cookie) {
            $event->getResponse()->headers->setCookie($cookie);
        }
    }
}
