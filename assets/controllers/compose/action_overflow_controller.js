import { Controller } from '@hotwired/stimulus';

/**
 * The compose window's action bar on one row: icons that do not fit beside ⋮
 * are folded into it, from the right.
 *
 * The row used to wrap. At most widths that put the last two or three icons
 * on a second line, which cost the body a line of text and moved everything
 * after the break to wherever the break fell. Now the row is as wide as the
 * window, ⋮ is its last element, and while ⋮ would stick out past the end of
 * the row the rightmost foldable icon is taken out and listed at the top of
 * ⋮'s menu instead — in the order the icons stand in the bar, so the menu
 * reads like the end of the row it replaces.
 *
 * WHAT FOLDS. Every `item` target: a button, or a ui--dropdown wrapper whose
 * first button opens its menu. A folded button is hidden and pressed for real
 * when its menu entry is chosen, so every action, Stimulus binding and file
 * dialog behind it works unchanged. A folded wrapper keeps its menu in the
 * page — only its button goes — and opens that menu over ⋮ (openAt), because
 * the button it would otherwise open over is not on screen. Send and Aa are not
 * items and never fold.
 *
 * WHEN. Whenever the row or one of its icons changes size: the window resized
 * (#507), plain-text mode hiding the rich-text icons, an encrypted draft hiding
 * the assistant. An icon hidden for one of those reasons is not folded into
 * the menu either — it is not there to fold.
 */
export default class extends Controller {
    static targets = ['item', 'more', 'list'];

    connect() {
        this._observer = new ResizeObserver(() => this._schedule());
        this._observer.observe(this.element);
        this.itemTargets.forEach((item) => this._observer.observe(item));
        this.fit();
    }

    disconnect() {
        this._observer?.disconnect();
        cancelAnimationFrame(this._frame);
    }

    /**
     * Unfold everything, then fold from the right until ⋮ fits. Measured
     * rather than computed from widths written down anywhere: the icons'
     * sizes follow the breakpoint and the theme, and the Send pill's follows
     * the language.
     */
    fit() {
        if (false === this.hasMoreTarget) {
            return;
        }

        this.itemTargets.forEach((item) => item.removeAttribute('data-overflowed'));

        const present = this.itemTargets.filter((item) => this._trigger(item)?.getClientRects().length > 0);
        const folded = [];

        for (let i = present.length - 1; i >= 0 && this._overflows(); i--) {
            present[i].setAttribute('data-overflowed', '');
            folded.unshift(present[i]);
        }

        this._list(folded);
    }

    /** One fit per frame, however many sizes changed in it. */
    _schedule() {
        cancelAnimationFrame(this._frame);
        this._frame = requestAnimationFrame(() => this.fit());
    }

    _overflows() {
        const row = this.element.getBoundingClientRect();
        const more = this.moreTarget.getBoundingClientRect();

        return more.right > row.right + 0.5;
    }

    /** The button an item is pressed by: itself, or a dropdown's own button. */
    _trigger(item) {
        return item.matches('[data-controller~="ui--dropdown"]') ? item.querySelector(':scope > button') : item;
    }

    _list(folded) {
        if (false === this.hasListTarget) {
            return;
        }

        this.listTarget.replaceChildren(...folded.map((item) => this._entry(item)));
        this.listTarget.hidden = 0 === folded.length;
    }

    /** A menu row for a folded icon: its own icon and its own name. */
    _entry(item) {
        const trigger = this._trigger(item);
        const entry = document.createElement('button');
        const label = document.createElement('span');
        const icon = trigger.querySelector('i')?.cloneNode(true) ?? document.createElement('i');

        entry.type = 'button';
        entry.setAttribute('role', 'menuitem');
        entry.className = 'w-full flex items-center gap-2.5 px-3 py-2 text-sm text-ink-muted hover:bg-hover hover:text-ink transition-colors text-left';
        icon.classList.add('w-4', 'text-center');
        icon.setAttribute('aria-hidden', 'true');
        label.textContent = trigger.getAttribute('aria-label') ?? trigger.getAttribute('title') ?? '';
        entry.append(icon, label);

        // The editor keeps its selection, as on the bar itself: the link and
        // signature buttons work on it.
        entry.addEventListener('mousedown', (event) => event.preventDefault());
        entry.addEventListener('click', (event) => {
            event.preventDefault();
            this._choose(item);
        });

        return entry;
    }

    _choose(item) {
        const more = this.application.getControllerForElementAndIdentifier(this.moreTarget, 'ui--dropdown');
        const anchor = this.moreTarget.querySelector(':scope > button');

        more?.close();

        const dropdown = item.matches('[data-controller~="ui--dropdown"]')
            ? this.application.getControllerForElementAndIdentifier(item, 'ui--dropdown')
            : null;

        if (null !== dropdown) {
            dropdown.openAt(anchor);

            return;
        }

        this._trigger(item)?.click();
    }
}
