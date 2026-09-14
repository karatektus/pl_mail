import { Controller } from '@hotwired/stimulus';

/**
 * The account menu behind the avatar.
 *
 * Predates ui--dropdown and keeps its own controller, which is why the entrance
 * and the outside-click close are spelt out here rather than inherited.
 *
 * WHY IT GOES TO THE TOP LAYER
 *
 * It used to rely on `z-[200]`, and a number like that reads as "above
 * everything" while meaning nothing of the sort: it orders the menu inside the
 * HEADER's stacking context, and says nothing about the header against the rest
 * of the page. So the menu was above the application only while nothing outside
 * the header created a context of its own — and the calendar keeps growing
 * them, especially once a background image switches the panes' backdrop-filter
 * on. Reported as the menu sitting behind calendar furniture.
 *
 * `showPopover()` puts it in the top layer, which is above every stacking
 * context in the document by construction, so there is no longer a number to
 * lose. See tests/e2e/menu-escapes-pane.spec.ts, which is the same fix for the
 * same reason on the menus inside the panes.
 *
 * WHAT THE TOP LAYER COSTS, and what is done about it: a popover's containing
 * block is the viewport, so `absolute right-0 mt-2` — which anchored it under
 * the avatar — stops meaning anything the moment it is shown. The position is
 * therefore measured and written here, from the button's own box. CSS anchor
 * positioning would do it declaratively and is not reachable yet: this menu has
 * to keep working in browsers that have `showPopover` and not `anchor-name`.
 */
export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this.onDocumentClick = this.onDocumentClick.bind(this);
        this.onViewportChange = this.onViewportChange.bind(this);

        document.addEventListener('click', this.onDocumentClick);
        window.addEventListener('resize', this.onViewportChange);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        window.removeEventListener('resize', this.onViewportChange);

        // Leaving a popover open in the top layer outlives the controller that
        // owns it: a Turbo navigation swaps the body and the menu would sit
        // over the new page with nothing left to close it.
        this.#close();
    }

    toggle(event) {
        event.stopPropagation();

        if (true === this.menuTarget.classList.contains('hidden')) {
            this.#open();

            return;
        }

        this.#close();
    }

    onDocumentClick(event) {
        if (false === this.element.contains(event.target)) {
            this.#close();
        }
    }

    /**
     * The anchor moved under an open menu — follow it.
     *
     * Repositioned rather than closed, because the width this reacts to is the
     * one that moves the avatar: the header is a flex row, so every resize
     * slides the button along it. A menu that shut itself on resize would also
     * shut on a phone's address bar retracting.
     */
    onViewportChange() {
        if (false === this.menuTarget.classList.contains('hidden')) {
            this.#place();
        }
    }

    // ── Private ─────────────────────────────────────────────────────────────

    #open() {
        this.menuTarget.classList.remove('hidden');

        if (false === this.#canEscape()) {
            return;
        }

        this.menuTarget.showPopover();
        this.#place();
    }

    #close() {
        this.menuTarget.classList.add('hidden');

        // `hidePopover()` throws if it is not open, which happens on the first
        // outside click of a page whose menu was never opened.
        if (true === this.#canEscape() && true === this.menuTarget.matches(':popover-open')) {
            this.menuTarget.hidePopover();
        }

        this.menuTarget.style.removeProperty('position');
        this.menuTarget.style.removeProperty('top');
        this.menuTarget.style.removeProperty('left');
    }

    /**
     * Put the menu under the avatar, and keep it on screen.
     *
     * Right-aligned to the button, which is what `right-0` did while the menu
     * was positioned against it. Clamped to the viewport afterwards, because at
     * a narrow width right-aligning a 224px menu to a button near the left edge
     * would hang it off the side — the old absolute version was clipped by the
     * header instead, which at least hid the overflow.
     */
    #place() {
        const anchor = this.element.querySelector('button');

        if (null === anchor) {
            return;
        }

        const box = anchor.getBoundingClientRect();
        const menu = this.menuTarget.getBoundingClientRect();
        const gutter = 8;

        const left = Math.min(
            Math.max(gutter, box.right - menu.width),
            Math.max(gutter, window.innerWidth - menu.width - gutter),
        );

        this.menuTarget.style.position = 'fixed';
        this.menuTarget.style.top = `${Math.round(box.bottom + gutter)}px`;
        this.menuTarget.style.left = `${Math.round(left)}px`;
    }

    /**
     * Whether this browser has the top layer at all.
     *
     * Checked rather than assumed so the menu degrades to what it always did —
     * an absolutely positioned panel that is usually on top — instead of
     * throwing on open and leaving the avatar inert.
     */
    #canEscape() {
        return 'function' === typeof this.menuTarget.showPopover;
    }
}
