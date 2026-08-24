<?php

declare(strict_types=1);

namespace App\Security\Csp;

use App\Service\Mail\MessageFrameScript;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A Content-Security-Policy on the application's own document.
 *
 * The reading frame carries a carefully built policy and the image proxy
 * carries one too. The page those live inside — the composer, settings, admin,
 * everything holding the session — carried none. CODE-REVIEW.md S-03 named that
 * as the amplifier that turned S-01 and S-02 from "script in a contenteditable"
 * into "take the account": with a policy, both would have been malfunctions
 * rather than incidents.
 *
 * ## Two headers, and why that is the whole design
 *
 * A single policy would have to be either weak enough to be safe to switch on
 * or strong enough to be worth having. Splitting them gets both:
 *
 * **Enforced** carries only directives that cannot plausibly break this
 * application and each of which closes a real escalation route. `base-uri
 * 'none'` is the one worth naming: without it, an injected `<base>` tag
 * silently re-points every relative URL on the page — including the module
 * imports this app is built out of — at another origin, which turns a foothold
 * into total control and is invisible in the markup. `object-src 'none'`
 * retires a whole class of plugin-based bypasses. `form-action 'self'` stops an
 * injected form posting the session somewhere else, and `frame-ancestors
 * 'self'` is the modern spelling of the X-Frame-Options the Caddyfile sends.
 *
 * **The full policy**, `script-src` included, is ENFORCED in production and
 * sent as Report-Only under debug. Not because production is trusted less
 * carefully, but because the profiler toolbar and VarDumper inject inline
 * scripts that nothing can nonce — they exist only when debug is on, and they
 * are the only violations left. Enforcing there would break the toolbar and
 * teach everyone to ignore the header.
 *
 * It reached "enforced" by having the violations removed rather than allowed.
 * The last of them was the application's own stylesheets: AssetMapper
 * implements `import './app.css'` as a `data:application/javascript,` module
 * that appends a <link> at runtime, so every stylesheet was a script as far as
 * the browser was concerned, and the only way to permit them would have been
 * `script-src … data:` — which readmits one of the oldest injection vectors
 * there is. The two stylesheets are <link>ed from the layout now (see the note
 * at the top of assets/app.js), which costs nothing, removes a
 * flash-of-unstyled-content, and is what makes this directive worth having.
 *
 * ## The nonce
 *
 * `script-src` cannot be `'self'` alone, because the layout has genuine inline
 * scripts — the theme bootstrap that has to run before first paint, and the
 * import map itself. `'unsafe-inline'` would defeat the point, so both carry a
 * per-request nonce from CspNonce, which is also what the header interpolates.
 * The two must be the same string, which is why that value is a service rather
 * than generated at either end.
 *
 * ## Where it does not apply
 *
 * HTML responses only. Attachments, the image proxy and the reading frame each
 * send their own, stricter policy and must not have it overwritten; Turbo
 * Streams, JSON and binaries have no document to protect. A response that
 * already carries a policy is left exactly as it is.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -128)]
final readonly class ContentSecurityPolicyListener
{
    /**
     * Safe to enforce today, and each one closes a real route.
     *
     * Deliberately no `default-src`. It would be the strongest single line here
     * and it is also the one that cannot be added without knowing every source
     * every page will ever pull — which is what the report-only policy below is
     * for finding out. A wrong `default-src` does not warn, it breaks the app.
     */
    private const string ENFORCED =
        "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'";

    /**
     * The target, soaking. `%nonce%` is replaced per request.
     *
     * `style-src 'unsafe-inline'` is not an oversight: Tailwind's theme
     * variables and the inline styles mail bodies depend on both need it, and
     * it is not the directive carrying the weight — `script-src` is.
     *
     * `img-src` allows data: and blob: because attachment thumbnails and pasted
     * images use both. `connect-src 'self'` covers the Mercure hub, which the
     * app's own Caddy proxies under /.well-known/mercure — same origin, which
     * is exactly why that proxying was worth having.
     *
     * `script-src` also names the HASH of the message frame's height reporter.
     * A srcdoc frame inherits this policy on top of its own, so that script has
     * to be authorised HERE as well as there — and it cannot be done with the
     * nonce above, because the frame is usually rendered by a later request
     * than the page hosting it. See MessageFrameScript.
     */
    private const string FULL =
        "default-src 'self'; "
        . "script-src 'self' 'nonce-%nonce%' '%frame-script%'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; "
        . "font-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'none'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'";

    public function __construct(
        private CspNonce           $nonce,
        private MessageFrameScript $frameScript,
        private bool               $debug,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        if (false === $this->isDocument($response)) {
            return;
        }

        // Set by a controller that knows better than this listener does — the
        // attachment route's sandbox, the image proxy's. Never overwritten.
        if (true === $response->headers->has('Content-Security-Policy')) {
            return;
        }

        $full = str_replace(
            ['%nonce%', '%frame-script%'],
            [$this->nonce->value(), $this->frameScript->hash()],
            self::FULL,
        );

        if (false === $this->debug) {
            $response->headers->set('Content-Security-Policy', $full);

            return;
        }

        // Debug: the always-safe directives are still enforced, and the full
        // policy rides along as a report so a violation introduced while
        // developing shows up in the console at the moment it is written rather
        // than after a deploy.
        $response->headers->set('Content-Security-Policy', self::ENFORCED);
        $response->headers->set('Content-Security-Policy-Report-Only', $full);
    }

    /**
     * An HTML document, as opposed to everything else this application answers.
     *
     * Turbo Streams are excluded by their own content type, and that is correct
     * rather than an omission: a stream is applied into a document that already
     * carries the policy of the response that delivered it, and the nonce of
     * THAT response. Sending a second, different nonce with a fragment would
     * mean any inline script in the fragment could never match either header.
     */
    private function isDocument(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type', ''), 'text/html');
    }
}
