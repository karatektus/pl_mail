<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Whether a sidebar row is the one you are on, decided while the page is
 * rendered rather than after it has painted.
 *
 * ui--sidebar used to be the only thing that knew. The rows went out with no
 * colour class at all, and the controller's first pass on connect gave the
 * open one `bg-accent-soft text-accent` and every other one `text-ink-muted`.
 * By then the page had been styled at least once — the first paint, or
 * anything earlier that made the browser resolve styles — and every row carries
 * `transition-colors` for its hover. So on every load, and on every Turbo
 * visit that re-rendered the sidebar, the open row faded in over 150ms from
 * the inherited black and a transparent pill, and the rest faded from black
 * to their muted ink. Measured in Chromium against the test stack: first
 * paint at ~200ms with the rows black, the pass at ~250ms, the transitions
 * starting in the frame after it.
 *
 * Rendering the classes here makes that pass a no-op on everything the server
 * drew: the first style the browser resolves is already the final one, so
 * there is nothing to transition from, and the hover transitions — declared on
 * the rows, triggered by :hover — are untouched. Same answer the More
 * disclosure's `open` and the collapsed label trees already give to the same
 * question: the server knows where you are, so it says so in the HTML.
 *
 * The controller keeps its pass, for everything the server cannot see. A
 * folder click is a FRAME navigation that re-renders no sidebar, so moving the
 * highlight there is the pass's job — a real change of place, and fading it is
 * right. Rows that arrive later — an account's folder list fetched into its
 * own frame, a label list re-rendered by a Turbo Stream — are rendered for
 * that request's URL rather than the page's, come out closed, and are marked
 * by the pass once they land. That correction still fades (measured: the open
 * folder in a freshly fetched list runs the same two transitions), which is
 * the one case this does not reach; it follows a click that changed the list,
 * never a load.
 *
 * ── The comparison is ui--sidebar#_matches, and has to stay it ─────────────
 * A row this marks open and the controller then closes (or the reverse) is
 * the fade back again, one row at a time. So the rule is the controller's,
 * restated: the path is the link's path or lies under it — the prefix is what
 * keeps a parent label open while you are in a child, and the slash is what
 * keeps "Work" from claiming "Workshop" — and a link that names an account
 * matches only that account. The class lists are the controller's too; see
 * SidebarRowStateTest, which reads both files.
 *
 * The MAIN request, because that is the one whose URL the browser is showing.
 * A stream or a frame fetch renders these rows for a URL no row points at, so
 * everything comes out closed there, which is the correct thing to hand the
 * controller.
 */
final class SidebarRowStateExtension extends AbstractExtension
{
    /**
     * The open row: the filled pill. `is-active` is also the hook the pill's
     * shape and mail--dnd's "this is where you are" check hang off.
     *
     * Kept identical to ACTIVE_CLASSES in assets/controllers/ui/sidebar_controller.js.
     */
    public const string OPEN = 'is-active bg-accent-soft text-accent font-medium';

    /** Kept identical to INACTIVE_CLASSES in the same file. */
    public const string CLOSED = 'text-ink-muted hover:bg-hover';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sidebar_row_state', $this->rowState(...)),
        ];
    }

    /**
     * The classes for the row whose link points at $href.
     */
    public function rowState(string $href): string
    {
        return true === $this->isOpen($href) ? self::OPEN : self::CLOSED;
    }

    public function isOpen(string $href): bool
    {
        $request = $this->requestStack->getMainRequest();
        $target  = parse_url($href);

        if (null === $request || false === $target || false === isset($target['path'])) {
            return false;
        }

        // What `window.location.pathname` is to the controller: base URL
        // included, and still percent-encoded — which is how path() writes the
        // href, so the two compare like for like.
        $path = $request->getBaseUrl() . $request->getPathInfo();

        if ($path !== $target['path'] && false === str_starts_with($path, $target['path'] . '/')) {
            return false;
        }

        parse_str($target['query'] ?? '', $query);

        $wanted = $query['account'] ?? null;

        if (null === $wanted) {
            return true;
        }

        // all() rather than get(): get() throws on `?account[]=`, and a
        // malformed query string must not be able to take the sidebar down.
        $current = $request->query->all()['account'] ?? null;

        return is_string($current) && $wanted === $current;
    }
}
