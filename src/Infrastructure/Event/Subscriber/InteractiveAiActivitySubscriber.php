<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Service\Ai\AiAssistant;
use App\Service\Ai\InteractiveAiActivity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tells the backfill that somebody is using the AI right now.
 *
 * WHY A LISTENER AND NOT A CALL INSIDE THE FEATURES
 * ─────────────────────────────────────────────────
 * The features would be the obvious place, and it is the wrong one for the
 * thing being measured. What the backfill needs to know is that a person is
 * WAITING — which begins when the request arrives, not when the model is
 * finally asked, and which is true for the whole of a streamed draft that will
 * not record a metrics row for another thirty seconds. A listener at the edge
 * of the request sees exactly that window; a call inside WritingAssistant sees
 * the middle of it.
 *
 * It also keeps the signal out of code that belongs to the composer and the
 * search: neither of them should have to remember that a backfill exists.
 *
 * BOTH ENDS OF THE REQUEST
 * ────────────────────────
 * Stamped on the way in so a long request counts from its start, and again on
 * the way out so the cooldown is measured from when the person actually got
 * their answer. Two single-row updates on a request that has just spent seconds
 * of GPU time.
 *
 * ROUTES, NOT PATHS, AND MATCHED BY PREFIX
 * ────────────────────────────────────────
 * The prefix is what makes this survive the routes growing: a streamed variant
 * of the compose assist endpoint is still `app_compose_assist…` and is still
 * interactive work. Search is included whether or not the query ends up going
 * anywhere near a model — when semantic search is on it always does, and when
 * it is off there is no backfill running to be held back.
 */
final readonly class InteractiveAiActivitySubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private const array INTERACTIVE_ROUTE_PREFIXES = [
        'app_compose_assist',
        'app_mail_search',
    ];

    public function __construct(
        private InteractiveAiActivity $activity,
        private AiAssistant           $ai,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Late enough that the router has run — the route name is what this
            // matches on, and before RouterListener there is none.
            KernelEvents::REQUEST  => ['onKernelRequest', 0],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $this->stamp((string) $event->getRequest()->attributes->get('_route'));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $this->stamp((string) $event->getRequest()->attributes->get('_route'));
    }

    private function stamp(string $route): void
    {
        if (false === self::isInteractive($route)) {
            return;
        }

        // The master switch, checked first because it is the cheap half: one
        // indexed row Doctrine answers from its identity map for the rest of
        // the request, against two writes. An installation that has never
        // turned the AI on never grows the state row at all.
        if (false === $this->ai->settings()->isEnabled) {
            return;
        }

        $this->activity->touch();
    }

    private static function isInteractive(string $route): bool
    {
        foreach (self::INTERACTIVE_ROUTE_PREFIXES as $prefix) {
            if (true === str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
