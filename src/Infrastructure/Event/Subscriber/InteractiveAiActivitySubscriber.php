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
 * interactive work.
 *
 * THE SEARCH ROUTE IS DELIBERATELY NOT HERE
 * ─────────────────────────────────────────
 * It used to be, and putting it back would undo the point of the change that
 * removed it. plMail runs TWO models and they are nothing alike:
 *
 *  · the WRITING model — 20.3 GiB, about eighteen seconds to load cold. The
 *    composer and the reading pane's thread summaries both use it, and in both
 *    somebody is sitting watching a cursor while it runs. That is the case this
 *    whole signal was built for, and those two are the cases that are left.
 *    The rule is WHICH MODEL, not "writing help specifically": a summary is the
 *    same 20.3 GiB behind the same one GPU, and a person waiting forty seconds
 *    for one must not also wait behind an indexing batch.
 *  · the EMBEDDING model — well under a gigabyte, a couple of seconds cold.
 *    BOTH semantic search and the indexer use it, and that shared use is the
 *    reason a search must not stamp here: indexing right after a search reuses
 *    the model that search just warmed, and by the time this listener runs on
 *    the response the person already has their results. A search that suppressed
 *    indexing for ninety seconds was throwing away the one moment when indexing
 *    is cheapest — and on an installation where somebody searches often, that
 *    was most of the day.
 *
 * A finished search is an invitation to index, not a reason to stand aside; see
 * SearchController, which queues a small catch-up batch on exactly that event.
 * AiCallMetricRepository::lastInteractiveCallAt() names the same workloads for
 * the same reason, and both halves have to agree or the yielding comes back
 * through the other one. When the summary route was added here, that predicate
 * gained 'thread_summary' in the same edit.
 */
final readonly class InteractiveAiActivitySubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private const array INTERACTIVE_ROUTE_PREFIXES = [
        'app_compose_assist',
        'app_mail_thread_summary',
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
