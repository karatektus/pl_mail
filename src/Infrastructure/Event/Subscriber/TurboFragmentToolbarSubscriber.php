<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keep the web debug toolbar out of Turbo fragment responses.
 *
 * WebDebugToolbarListener injects its markup into any text/html response,
 * including the bare fragments Turbo swaps into a frame. Inside the compose
 * window that markup lands in the contenteditable body and gets saved with
 * the draft — a 76 KB mail carrying the profiler around, re-quoted into every
 * reply after it.
 *
 * Dropping X-Debug-Token makes the listener skip the response; the profiler
 * itself still records the request.
 */
#[When('dev')]
final class TurboFragmentToolbarSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // WebDebugToolbarListener runs at -128; this has to be earlier.
        return [KernelEvents::RESPONSE => ['onKernelResponse', -100]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        $isFragment = $request->headers->has('Turbo-Frame')
            || str_contains((string) $request->headers->get('Accept'), 'turbo-stream')
            || 'fetch' === $request->headers->get('X-Requested-With');

        if (false === $isFragment) {
            return;
        }

        $event->getResponse()->headers->remove('X-Debug-Token');
        $event->getResponse()->headers->remove('X-Debug-Token-Link');
    }
}
