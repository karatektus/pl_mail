import { Controller } from '@hotwired/stimulus';

/**
 * The installed app's title bar, in the top bar's colour (#589).
 *
 * Chrome paints what it draws around an installed app — the whole title bar,
 * or with the title bar hidden the strips behind the window buttons and its
 * own menu — in the page's `<meta name="theme-color">`. The server renders the
 * app's colour itself, under a `display-mode` condition ahead of the tab's
 * purple (AppearanceExtension::appThemeColours), so every page and every Turbo
 * visit already arrives right. A first version set it from here and watched
 * <head> for the server's purple coming back; the server knowing is simpler.
 *
 * What the server cannot know is a theme being TRIED: the appearance settings
 * repaint <html> before anything is saved. So a change to <html> repaints the
 * app's tags with the surface now on screen, read from a probe painted
 * `rgb(var(--rgb-surface))`. The next page load renders the saved one again.
 *
 * Second job, with the title bar hidden (html.app-titlebar): squaring the
 * panel corners that land halfway along a window edge, see #edges.
 *
 * Mounted on the topbar, which every app page has.
 */
export default class extends Controller {
    connect() {
        if (!this.#installed()) {
            return;
        }

        // The surface the server rendered for; tags are only rewritten when
        // the one on screen differs. Most changes to <html> (the sidebar rail,
        // the title bar class) are not a theme, and rewriting System's light
        // and dark tags with one colour for them would break the OS switch the
        // server's tags already handle.
        this.surface = this.#surface();
        this.paint = () => this.#paint();

        // Coalesced to one measurement a frame: a resize, a frame load and the
        // panes it resizes all ask at once.
        this.edges = () => {
            cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => this.#edges());
        };
        // Each box is observed once. A ResizeObserver reports every box as
        // soon as it is observed, so observing them all again per measurement
        // would ask for the next measurement, forever.
        this.resizes = new ResizeObserver(this.edges);
        this.watched = new Set();
        window.addEventListener('resize', this.edges);
        document.addEventListener('turbo:frame-load', this.edges);
        this.edges();

        // Theme, layout and colour changes all land on <html>: its class
        // (dark, the layout), data-theme, and the inline custom properties.
        this.observer = new MutationObserver(() => {
            this.paint();
            this.edges();
        });
        this.observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'style', 'data-theme'],
        });
    }

    disconnect() {
        this.observer?.disconnect();
        this.resizes?.disconnect();
        cancelAnimationFrame(this.frame);
        window.removeEventListener('resize', this.edges);
        document.removeEventListener('turbo:frame-load', this.edges);
    }

    #installed() {
        return ['standalone', 'minimal-ui', 'window-controls-overlay']
            .some((mode) => window.matchMedia(`(display-mode: ${mode})`).matches);
    }

    /**
     * Marks the panel corners that sit on exactly ONE window edge: the top
     * bar's lower corners, the sidebar's upper-left and lower-right, a
     * content pane's lower corners and, at the right, its upper-right. A
     * rounded corner there is a notch in the window's edge. A corner on two
     * edges is the window's own corner and stays as the window draws it; one
     * on none faces another panel and stays round. app.css squares what is
     * marked (`data-edge`).
     *
     * Measured rather than written per panel, because which pane touches which
     * edge changes with the layout: a calendar beside the mail, a reading
     * pane, the settings page's own columns. Only boxes that touch an edge are
     * descended into, so a long mail list is never walked.
     */
    #edges() {
        const root = document.documentElement;

        document.querySelectorAll('[data-edge]').forEach((el) => el.removeAttribute('data-edge'));

        for (const el of this.watched) {
            if (!el.isConnected) {
                this.resizes.unobserve(el);
                this.watched.delete(el);
            }
        }

        const frame = document.querySelector('.shell-frame');

        if (null === frame || !root.classList.contains('app-titlebar')) {
            return;
        }

        const w = root.clientWidth;
        const h = root.clientHeight;
        const marks = [];

        const walk = (el, depth) => {
            for (const child of el.children) {
                const r = child.getBoundingClientRect();

                if (0 === r.width || 0 === r.height) continue;

                const left = r.left <= 1, right = r.right >= w - 1;
                const top = r.top <= 1, bottom = r.bottom >= h - 1;

                if (!(left || right || top || bottom)) continue;

                if (!this.watched.has(child)) {
                    this.watched.add(child);
                    this.resizes.observe(child);
                }

                const cs = getComputedStyle(child);
                const corners = [
                    ['tl', cs.borderTopLeftRadius, top, left],
                    ['tr', cs.borderTopRightRadius, top, right],
                    ['bl', cs.borderBottomLeftRadius, bottom, left],
                    ['br', cs.borderBottomRightRadius, bottom, right],
                ].filter(([, radius, a, b]) => parseFloat(radius) > 0 && a !== b);

                if (corners.length > 0) {
                    marks.push([child, corners.map(([name]) => name).join(' ')]);
                }

                if (depth < 8) walk(child, depth + 1);
            }
        };

        walk(frame, 0);

        // Written after every box is read, so the reads are not interleaved
        // with style changes that would force a layout each.
        for (const [el, edge] of marks) {
            el.setAttribute('data-edge', edge);
        }
    }

    #surface() {
        const probe = document.createElement('span');
        probe.style.cssText = 'position:absolute;visibility:hidden;background-color:rgb(var(--rgb-surface))';
        document.body.append(probe);
        const colour = getComputedStyle(probe).backgroundColor;
        probe.remove();

        return colour;
    }

    #paint() {
        const colour = this.#surface();

        if (colour === this.surface) {
            return;
        }

        this.surface = colour;

        // Only the app's tags (the ones with a display-mode condition); the
        // tab's purple stays the tab's.
        for (const meta of document.querySelectorAll('meta[name="theme-color"][media*="display-mode"]')) {
            if (meta.content !== colour) {
                meta.content = colour;
            }
        }
    }
}
