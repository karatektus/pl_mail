<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Domain\Helper\RequestTimeLimit;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ends what RequestTimeLimit::hold() did, with the request that did it. Here
 * rather than beside each caller, because a controller that streams has already
 * returned by the time its work runs, and terminate comes after the response has
 * been sent.
 */
final class ReleaseTimeLimitSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'release'];
    }

    public function release(): void
    {
        RequestTimeLimit::release();
    }
}
