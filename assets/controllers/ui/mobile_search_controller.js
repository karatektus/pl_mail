import { Controller } from '@hotwired/stimulus';

/**
 * The search box on a phone: a button in the topbar, and the field itself as a
 * row that drops out from under it.
 *
 * Below md the field had roughly 120px of row to live in, between the logo and
 * the calendar button — narrow enough that a typed query scrolled out of its
 * own box, and wide enough to be the first thing a thumb landed on reaching for
 * anything else. So it leaves the row and comes back on request.
 *
 * This controller owns only the disclosure: whether the panel is showing, where
 * the focus goes, and what the button reports about itself. Everything the
 * search *is* — the suggestions, the recents, the listbox, the arrow keys —
 * stays in mail--search on the panel itself, and there is exactly one of it at
 * every width. The two shapes are the same field, moved; not two fields, which
 * would have meant two elements claiming `#search-listbox` and a screen reader
 * following whichever it found first.
 *
 * Above md none of this runs: the button is `md:hidden`, and the geometry is a
 * media query in app.css rather than anything written here. There is nothing to
 * undo on a rotation into landscape — the panel simply becomes the topbar field
 * again, which is why this deliberately does not listen for a breakpoint.
 */
export default class extends Controller {
    static targets = ['toggle', 'panel', 'field'];
    static values  = { openLabel: String, closeLabel: String };

    /**
     * Where the field stops being a topbar field and becomes a panel. Tailwind's
     * own `max-md:`, character for character: the boundary is 48 REM, and rem in
     * a media query answers to the browser's default-font-size preference, so a
     * px spelling of "the same thing" is only the same thing at 16px.
     */
    static MOBILE_QUERY = '(width < 48rem)';

    connect() {
        // The SAME query Tailwind compiles `md:` to — see `.search-shell` in
        // app.css for why every boundary in this feature has to be the one
        // written in rem.
        this._mq = window.matchMedia(this.constructor.MOBILE_QUERY);
        this._boundBreakpoint = this._applyBreakpoint.bind(this);
        this._mq.addEventListener('change', this._boundBreakpoint);

        // On the document rather than on this element: a tap lands on the mail
        // list behind the panel, which is not a descendant of anything here.
        this._boundOutside = this._closeOnOutside.bind(this);
        // Capture, and the reason is the Escape ladder mail--search already
        // has: its first Escape takes the suggestion list down and its second
        // clears the box. Closing the panel is the third rung, so this has to
        // read `aria-expanded` BEFORE that handler runs and changes it —
        // otherwise one press collapses all three and a half-typed query
        // disappears with the list.
        this._boundKeydown = this._closeOnEscape.bind(this);

        document.addEventListener('click', this._boundOutside);
        document.addEventListener('keydown', this._boundKeydown, true);
    }

    disconnect() {
        this._mq?.removeEventListener('change', this._boundBreakpoint);
        document.removeEventListener('click', this._boundOutside);
        document.removeEventListener('keydown', this._boundKeydown, true);
    }

    /**
     * Carry the field across the breakpoint rather than dropping it.
     *
     * An iPhone in landscape is 812 to 932 CSS pixels — above md, so the search
     * is the topbar field there — and turning it upright crosses to the panel
     * layout. Mid-query that meant the element being typed into was given
     * `display: none`: the focus fell to <body>, the keyboard dropped, and
     * nothing on screen said why. The text survived and the magnifier brought
     * it back, which is exactly the sort of thing nobody works out while it is
     * happening.
     *
     * So a crossing that finds the field in use opens the panel instead, and a
     * crossing the other way puts the flag back, so the next one starts from
     * the state the server renders.
     */
    _applyBreakpoint(event) {
        if (false === event.matches) {
            this._setOpen(false);

            return;
        }

        if (false === this.hasFieldTarget) {
            return;
        }

        const inUse = this.fieldTarget === document.activeElement
            || '' !== this.fieldTarget.value;

        if (true === inUse) {
            this._setOpen(true);
        }
    }

    toggle() {
        this._setOpen(false === this._isOpen());
    }

    _isOpen() {
        return this.hasPanelTarget && 'true' === this.panelTarget.dataset.open;
    }

    _setOpen(open) {
        if (false === this.hasPanelTarget) {
            return;
        }

        // An attribute rather than a class, so the closed state is the one the
        // server renders and a phone whose JavaScript never arrived gets the
        // button and no panel — rather than a panel wedged open under the
        // topbar with nothing able to close it.
        this.panelTarget.dataset.open = true === open ? 'true' : 'false';

        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-expanded', true === open ? 'true' : 'false');

            // The name changes with the state, because "Search" on a button
            // that closes the search is a lie the icon cannot correct.
            const label = true === open ? this.closeLabelValue : this.openLabelValue;

            if ('' !== label) {
                this.toggleTarget.setAttribute('aria-label', label);
                this.toggleTarget.setAttribute('title', label);
            }
        }

        if (false === this.hasFieldTarget) {
            return;
        }

        if (true === open) {
            // Synchronously, inside the click. iOS only raises the keyboard for
            // a focus() that happens in the gesture that asked for it; deferred
            // to a frame or a transition callback it opens the field and leaves
            // the user to tap it a second time.
            this.fieldTarget.focus();

            return;
        }

        // Give the focus back rather than dropping it on <body>, which is where
        // a keyboard user would otherwise have to start again from.
        if (this.fieldTarget === document.activeElement && this.hasToggleTarget) {
            this.toggleTarget.focus();
        }
    }

    _closeOnOutside(event) {
        if (false === this._isOpen() || this.element.contains(event.target)) {
            return;
        }

        this._setOpen(false);
    }

    _closeOnEscape(event) {
        if ('Escape' !== event.key || false === this._isOpen()) {
            return;
        }

        // mail--search's own two rungs first: while the suggestion list is up
        // this Escape belongs to it, and while there is still a query in the
        // box the next one belongs to clearing that. Only an Escape with
        // nothing left to dismiss puts the panel away.
        //
        // Both rungs are spent BY THE FIELD, so both are deferred to only while
        // the field holds the focus. Without that condition the gates read
        // state that nothing can then clear: Tab out of the box into the open
        // dropdown and `aria-expanded` stays "true" for good — mail--search
        // writes it from handlers bound to the input, and the input no longer
        // has the key. Escape stopped working from that moment on, permanently,
        // and the panel sat over the top of the message list until a pointer
        // put it away. The `value !== ''` gate was the easier of the two to
        // reach: type, press Escape once, Tab away, and rung two can never run
        // either.
        if (this.hasFieldTarget && this.fieldTarget === document.activeElement) {
            if ('true' === this.fieldTarget.getAttribute('aria-expanded')) {
                return;
            }

            if ('' !== this.fieldTarget.value) {
                return;
            }
        }

        this._setOpen(false);
    }
}
