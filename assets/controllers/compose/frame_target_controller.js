import { Controller } from '@hotwired/stimulus'

/**
 * Points a compose link at the dock while the compose window is fullscreen.
 *
 * Below md every compose window — a reply, a forward, a draft opened in its
 * row — is the whole screen, and a fullscreen window has to hang off <body>:
 * `position: fixed` is contained by any ancestor carrying a `backdrop-filter`,
 * and the thread pane has one. Rendered into `compose_inline` or
 * `compose_draft_{id}`, both of which live inside that pane, the window would
 * be trapped in it — inset by the pane's border and clipped to its box.
 *
 * So the frame is chosen per breakpoint rather than baked into the markup.
 * Doing it at connect (and again when the breakpoint flips) rather than on
 * click keeps it clear of Turbo, which reads `data-turbo-frame` as it handles
 * the click; rewriting it there would be a race.
 *
 * The href travels with it: the server reads `?frame=` to decide whether it is
 * rendering an inline card or a dock window, and it is the only thing that
 * says so on the autosave POST, which carries no Turbo-Frame header.
 */
export default class extends Controller {
    static MOBILE_QUERY = '(max-width: 767px)';
    static DOCK_FRAME = 'compose_dock';

    connect() {
        this._frame = this.element.dataset.turboFrame ?? this.constructor.DOCK_FRAME;
        this._href  = this.element.getAttribute('href');

        this._mq = window.matchMedia(this.constructor.MOBILE_QUERY);
        this._boundApply = this._apply.bind(this);
        this._mq.addEventListener('change', this._boundApply);

        this._apply();
    }

    disconnect() {
        this._mq?.removeEventListener('change', this._boundApply);
    }

    _apply() {
        const dock = true === this._mq.matches;

        this.element.dataset.turboFrame = dock ? this.constructor.DOCK_FRAME : this._frame;
        this.element.setAttribute('href', dock ? this._dockHref() : this._href);
    }

    /** The same link, asking the server for a dock window. */
    _dockHref() {
        const url = new URL(this._href, window.location.origin);

        url.searchParams.set('frame', this.constructor.DOCK_FRAME);

        return `${url.pathname}${url.search}`;
    }
}
