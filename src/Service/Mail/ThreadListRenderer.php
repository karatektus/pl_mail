<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\MessageThread;
use App\Repository\Mail\MessageThreadRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Render a thread list, then retire the "New" badges it just showed.
 *
 * A service rather than a method on MailController because SearchController
 * shows thread rows too, and a row the user has read the subject line of in a
 * search result has been shown — it must not be waiting to surprise them in the
 * inbox afterwards. Duplicating the collect-render-then-mark order into a second
 * controller is how the two would drift; there is one copy of it here.
 *
 * The ORDER is the feature. A thread is new until its row has been DISPLAYED —
 * not until it has been opened — so the marking has to happen against a page
 * that has already been turned into HTML. Marking first would mean the badge
 * was computed from state the same request had just destroyed, and no user
 * would ever see it: mail would arrive and quietly become old in the frame that
 * announced it.
 *
 * Scoped to the $threads handed in, which is one rendered page. Marking the
 * whole query instead would clear page 2 and 3 the moment page 1 was looked at.
 *
 * WHAT THIS CANNOT DO, and why there is a client half as well: the server knows
 * it produced HTML, never that the HTML reached a screen. Turbo 8 prefetches
 * links on hover and then SERVES THAT EXACT RESPONSE for the click, so a page
 * rendered under a prefetch header is very often the page the user ends up
 * looking at. Skipping the mark here was therefore not conservative, it was
 * permanent — see NewMailMarkerController on the client, which confirms the
 * display from the DOM and is what actually retires a prefetched list.
 */
final class ThreadListRenderer
{
    public function __construct(
        private readonly Environment             $twig,
        private readonly MessageThreadRepository $threadRepository,
    ) {
    }

    /**
     * @param MessageThread[]      $threads
     * @param array<string, mixed> $parameters
     */
    public function render(Request $request, string $template, array $threads, array $parameters): Response
    {
        $now = new \DateTimeImmutable();
        $new = [];

        foreach ($threads as $thread) {
            if (true === $thread->isNewAt($now)) {
                $new[] = (int) $thread->id;
            }
        }

        $parameters['threads']      = $threads;
        $parameters['newThreadIds'] = $new;

        $response = new Response($this->twig->render($template, $parameters));

        // A prefetch is a page that MAY never be looked at, so the server does
        // not retire anything on one. The client confirms the display instead
        // — which also covers the case this guard used to break, where the
        // prefetched response is the one that gets shown.
        if (true === self::isPrefetch($request)) {
            return $response;
        }

        $this->threadRepository->markListed($new, $now);

        return $response;
    }

    /**
     * Whether this request is a speculative fetch rather than a visit.
     *
     * Both spellings, because there are two mechanisms: Turbo sends its own
     * `X-Sec-Purpose` on hover prefetches, and browsers send the standard
     * `Sec-Purpose` for prerender and speculation-rules prefetching.
     */
    public static function isPrefetch(Request $request): bool
    {
        foreach (['X-Sec-Purpose', 'Sec-Purpose'] as $header) {
            if (str_contains((string) $request->headers->get($header), 'prefetch')) {
                return true;
            }
        }

        return false;
    }
}
