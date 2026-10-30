<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Integration\Driver\HttpLimits;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ends what a file transfer did to PHP's time limit, with the request that did
 * it. Here rather than beside each transfer, because the picker, the avatar and
 * whatever comes next would each have to remember, and a thread that kept the
 * raised limit fails silently, the next time something hangs.
 */
final class ReleaseHttpLimitsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'release'];
    }

    public function release(): void
    {
        HttpLimits::release();
    }
}
