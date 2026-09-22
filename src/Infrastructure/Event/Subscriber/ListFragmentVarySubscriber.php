<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Twig\ListFragmentGlobal;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tell caches that a mail list URL has more than one representation, and which
 * request headers choose between them.
 *
 * `/mail/inbox` answers three different documents depending on two headers: the
 * whole page, the page's head with only the list frame in its body
 * (`Turbo-Frame`), and the frame on its own with no head at all
 * (`X-List-Fragment`). Without `Vary` every cache between the renderer and the
 * screen files all three under the one URL and hands back whichever it stored
 * last.
 *
 * ── Why this exists, given that these responses are `private` ───────────────
 * ListFragmentGlobal used to argue that no `Vary` was needed, because a mail
 * list answers `Cache-Control: max-age=0, must-revalidate, private`: no shared
 * cache may store it, and the browser must revalidate before reusing its own
 * copy — so the ask would carry the header and select the right representation.
 * The second half of that is wrong, and it was measured wrong against Chromium
 * with a server logging every request:
 *
 *   1. `must-revalidate` governs STALENESS, not representation. The browser
 *      stores the response regardless, keyed by URL alone. Priming the cache
 *      with a `Turbo-Frame` fetch and then re-reading the same URL with NO such
 *      header — `fetch(url, {cache: 'only-if-cached'})` — returned the chrome-
 *      less FRAGMENT, from the store, with no request made.
 *   2. Revalidation does not fix it either, because revalidation is conditional
 *      and 304 means "reuse what you stored". With a validator present, a
 *      request carrying no `Turbo-Frame` at all — one the server answered by
 *      choosing the FULL page — was met with 304 and the browser rendered the
 *      stored fragment: correct URL, no sidebar. Exactly the v0.2.34 bug
 *      report, arriving with no prefetch involved at all.
 *
 * plMail's own list responses carry no ETag and no Last-Modified today, so
 * route 2 needs a validator from somewhere else to fire — and the deployment
 * this was reported from sits behind Nginx Proxy Manager, which is somewhere
 * else. Route 1 needs nothing. `private` was never the protection it was read
 * as: the browser's own cache is exactly the private cache it permits.
 *
 * `Vary` is the header that says "these two are not the same document", and it
 * costs one line. The old argument also rested on a `Cache-Control` value that
 * is a Symfony DEFAULT rather than a decision anyone here made — one `#[Cache]`
 * attribute on a list route and the whole thing would have quietly stopped
 * being true, with no test able to see it.
 *
 * ── Why it keys off the request rather than a list of routes ────────────────
 * Naming the routes that fork would mean a second list to keep in step with the
 * templates, and the regression this comes from was caused by exactly that kind
 * of split knowledge. Instead ListFragmentGlobal records, on the request, that
 * something asked it which representation to render. Any template that forks on
 * those headers is therefore covered the moment it forks, including the ones
 * that do not exist yet, and a URL that never asks never grows the header.
 *
 * Both representations get it, which matters: `Vary` on the fragment alone
 * would still let a cache serve a stored full page to a frame request.
 *
 * @see App\Twig\ListFragmentGlobal  where the fork is decided and recorded
 */
final class ListFragmentVarySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // A sub-request's response is thrown away after being inlined into its
        // parent, so a Vary set on one would never reach a cache. Nothing here
        // renders a mail list in a sub-request; if something ever does, this is
        // the line that will need to propagate the attribute upwards.
        if (false === $event->isMainRequest()) {
            return;
        }

        if (true !== $event->getRequest()->attributes->get(ListFragmentGlobal::VARIES_ATTRIBUTE)) {
            return;
        }

        $response = $event->getResponse();

        // Merged into what is already there rather than replacing it: `Vary`
        // is a set, and anything else that has a claim on this response's
        // representation — a compression listener adding `Accept-Encoding`,
        // most obviously — has put its own name in here first.
        //
        // Joined into ONE comma-separated header rather than handed over as an
        // array. Both are legal and mean the same thing, but Symfony turns an
        // array into one `Vary:` LINE PER ENTRY, and a response with two Vary
        // lines reads back through `$response->headers->get('Vary')` as only
        // the first of them — so the second looks absent to everything that
        // inspects it, tests included. Measured, and it cost a confused
        // half-hour.
        $vary = $response->getVary();

        $missing = array_diff(
            [ListFragmentGlobal::TURBO_HEADER, ListFragmentGlobal::HEADER],
            $vary,
        );

        if ([] === $missing) {
            return;
        }

        $response->setVary(implode(', ', [...$vary, ...$missing]));
    }
}
