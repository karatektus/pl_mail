import { Controller } from '@hotwired/stimulus';

/**
 * The installed app's title bar, in the top bar's colour (#589).
 *
 * Chrome paints what it draws around an installed app — the whole title bar,
 * or with the title bar hidden the strips behind the window buttons and its
 * own menu — in the page's `<meta name="theme-color">`. That is the brand
 * purple from the layout, which in a tab colours the browser's chrome on
 * phones and is right there; in the app it was a purple band glued to a top
 * bar of a different colour.
 *
 * So in the app the meta tag is set to the top bar's surface colour, and kept
 * there: a theme switch, the system going dark, or a Turbo visit (whose head
 * merge puts the server's purple back before this element reconnects) all
 * repaint it. In a tab nothing is touched.
 *
 * The colour is read from a probe painted `rgb(var(--rgb-surface))` rather
 * than from the bar itself, because with the title bar shown the bar is still
 * the translucent card, and Chrome takes the theme-color opaque.
 *
 * Mounted on the topbar, which every app page has.
 */
export default class extends Controller {
    connect() {
        if (!this.#installed()) {
            return;
        }

        this.paint = () => this.#paint();
        this.paint();

        // Theme, layout and colour changes all land on <html>: its class
        // (dark, the layout), data-theme, and the inline custom properties.
        this.observer = new MutationObserver(this.paint);
        this.observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'style', 'data-theme'],
        });

        this.scheme = window.matchMedia('(prefers-color-scheme: dark)');
        this.scheme.addEventListener('change', this.paint);
    }

    disconnect() {
        this.observer?.disconnect();
        this.scheme?.removeEventListener('change', this.paint);
    }

    #installed() {
        return ['standalone', 'minimal-ui', 'window-controls-overlay']
            .some((mode) => window.matchMedia(`(display-mode: ${mode})`).matches);
    }

    #paint() {
        const probe = document.createElement('span');
        probe.style.cssText = 'position:absolute;visibility:hidden;background-color:rgb(var(--rgb-surface))';
        document.body.append(probe);
        const colour = getComputedStyle(probe).backgroundColor;
        probe.remove();

        let meta = document.querySelector('meta[name="theme-color"]');

        if (null === meta) {
            meta = document.createElement('meta');
            meta.name = 'theme-color';
            document.head.append(meta);
        }

        if (meta.content !== colour) {
            meta.content = colour;
        }
    }
}
