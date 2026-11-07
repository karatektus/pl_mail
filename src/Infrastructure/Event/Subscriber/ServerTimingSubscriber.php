<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Infrastructure\Doctrine\Timing\QueryClock;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A `Server-Timing` header on every response to somebody signed in: how long
 * the request took on the server and how much of that was the database.
 *
 *     Server-Timing: db;dur=38.2;desc="9 queries", app;dur=61.5
 *
 * Browsers show it in the network panel's Timing tab next to the wait and the
 * download, and `performance.getEntriesByType('resource')` hands it to a
 * script as `serverTiming`. That is how a slow click on the NAS plMail runs on
 * is told apart into network, Postgres and PHP without a profiler there: the
 * list that took 65 ms on a laptop took 290 ms on the NAS, and until now the
 * split could only be inferred by timing other endpoints from outside.
 *
 * `app` runs from the start of the kernel's handling to the response, so it
 * leaves out what comes after — sending the body, the session write — and
 * the time before PHP got the request. `db` is the statements' own time as
 * QueryClock counts it; `app` minus `db` is PHP and Twig.
 *
 * Only for a signed-in reader. The numbers say nothing about anybody else's
 * data, but a sign-in page that answers how long a lookup took is the start
 * of a timing oracle, and nobody signed out has a reason to read them.
 */
final class ServerTimingSubscriber implements EventSubscriberInterface
{
    private ?int $start = null;

    public function __construct(
        private readonly QueryClock $clock,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // First of everything, so the firewall and the session are inside it.
            KernelEvents::REQUEST  => ['onKernelRequest', 4096],
            // Last, after the listeners that still query (the CSP report, the
            // cookies) have had their turn.
            KernelEvents::RESPONSE => ['onKernelResponse', -4096],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (true === $event->isMainRequest()) {
            $this->start = hrtime(true);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (false === $event->isMainRequest() || null === $this->start || null === $this->security->getUser()) {
            return;
        }

        $app         = (hrtime(true) - $this->start) / 1e6;
        $this->start = null;

        $event->getResponse()->headers->set('Server-Timing', sprintf(
            'db;dur=%.1f;desc="%d %s", app;dur=%.1f',
            $this->clock->milliseconds,
            $this->clock->queries,
            1 === $this->clock->queries ? 'query' : 'queries',
            $app,
        ));
    }
}
