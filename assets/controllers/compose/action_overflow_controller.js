import { Controller } from '@hotwired/stimulus';
import { csrfToken } from '../../csrf.js';

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
 *
 * ORDER. The buttons after Send stand in the person's own order: each carries
 * `data-compose-action` and a CSS `order` from the server
 * (compose_action_order()), so "from the right" and "in the order they stand"
 * below mean the order on screen, not the order in the markup. "Arrange
 * buttons" under ⋮ lets the row wrap, shows every button, and lets them be
 * dragged — mouse, pen or finger — or moved with the arrow keys while one has
 * the focus. Done stores the order in the person's settings, Reset goes back
 * to the default, Escape leaves it as it was. Nothing in the bar works while
 * arranging: a click is the start of a move, not a press.
 */
export default class extends Controller {
    static targets = ['item', 'more', 'list', 'arrangeBar', 'doneButton'];

    static values = {
        url: String,
        defaultOrder: Array,
    };

    connect() {
        this._observer = new ResizeObserver(() => this._schedule());
        this._observer.observe(this.element);
        this.itemTargets.forEach((item) => this._observer.observe(item));

        // Another compose window on the page stored an order: take it, so two
        // docked windows never disagree about where a button is.
        this._onOrderStored = (event) => {
            if (event.detail?.source !== this) {
                this._applyOrder(event.detail?.order ?? this.defaultOrderValue);
                this.fit();
            }
        };
        window.addEventListener('plmail:compose-action-order', this._onOrderStored);

        this.fit();
    }

    disconnect() {
        this._observer?.disconnect();
        cancelAnimationFrame(this._frame);
        window.removeEventListener('plmail:compose-action-order', this._onOrderStored);
        this._leaveArranging();
    }

    /**
     * Unfold everything, then fold from the right until ⋮ fits. Measured
     * rather than computed from widths written down anywhere: the icons'
     * sizes follow the breakpoint and the theme, and the Send pill's follows
     * the language.
     */
    fit() {
        if (false === this.hasMoreTarget || true === this._arranging) {
            return;
        }

        this.itemTargets.forEach((item) => item.removeAttribute('data-overflowed'));

        const present = this._byOrder(this.itemTargets)
            .filter((item) => this._trigger(item)?.getClientRects().length > 0);
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

    // ── Arranging ─────────────────────────────────────────────────────

    /** "Arrange buttons" under ⋮. */
    arrange() {
        if (true === this._arranging || false === this.hasArrangeBarTarget) {
            return;
        }

        this._arranging = true;
        this._before = this._order();

        // Every button on show, on as many lines as it takes: one that stayed
        // folded into ⋮ could not be moved, and ⋮ itself is not one to move.
        this.itemTargets.forEach((item) => item.removeAttribute('data-overflowed'));
        this.element.classList.replace('flex-nowrap', 'flex-wrap');
        this.moreTarget.hidden = true;
        this.arrangeBarTarget.hidden = false;
        this._sortables().forEach((item) => item.classList.add(...ARRANGING));

        this._onPointerDown = (event) => this._dragStart(event);
        this._onClick = (event) => this._swallow(event);
        this._onKeyDown = (event) => this._key(event);
        this.element.addEventListener('pointerdown', this._onPointerDown, true);
        this.element.addEventListener('click', this._onClick, true);
        this.element.addEventListener('keydown', this._onKeyDown, true);

        this._focusable(this._visible()[0])?.focus({ preventScroll: true });
    }

    /** Back to the default order, still arranging: Done stores it. */
    resetOrder() {
        this._applyOrder(this.defaultOrderValue);
    }

    /** Store the order and leave. */
    async done() {
        const order = this._order();
        const isDefault = order.join() === this._full(this.defaultOrderValue).join();

        this._leaveArranging();
        this.fit();

        window.dispatchEvent(new CustomEvent('plmail:compose-action-order', {
            detail: { order: true === isDefault ? null : order, source: this },
        }));

        if ('' === this.urlValue) {
            return;
        }

        try {
            await fetch(this.urlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken(),
                },
                body: JSON.stringify({ order: true === isDefault ? null : order }),
            });
        } catch (_) {
            // Kept on screen for this window either way; the next window drawn
            // shows what the server has.
        }
    }

    _leaveArranging() {
        if (true !== this._arranging) {
            return;
        }

        this._arranging = false;
        this._dragEnd();
        this.element.removeEventListener('pointerdown', this._onPointerDown, true);
        this.element.removeEventListener('click', this._onClick, true);
        this.element.removeEventListener('keydown', this._onKeyDown, true);
        this._sortables().forEach((item) => item.classList.remove(...ARRANGING));
        this.element.classList.replace('flex-wrap', 'flex-nowrap');

        if (true === this.hasMoreTarget) {
            this.moreTarget.hidden = false;
        }

        if (true === this.hasArrangeBarTarget) {
            this.arrangeBarTarget.hidden = true;
        }
    }

    /** Escape: as it was before arranging. */
    _cancel() {
        this._applyOrder(this._before ?? this._order());
        this._leaveArranging();
        this.fit();
    }

    /** A press on a button is the start of a move while arranging, never a press. */
    _swallow(event) {
        if (null !== event.target.closest('[data-compose-action]')) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    _key(event) {
        if ('Escape' === event.key) {
            event.preventDefault();
            event.stopPropagation();
            this._cancel();

            return;
        }

        const item = event.target.closest('[data-compose-action]');
        const step = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[event.key];

        if (null === item || undefined === step) {
            // Enter or Space would press the button; a move is all a key does here.
            if (null !== item && ('Enter' === event.key || ' ' === event.key)) {
                event.preventDefault();
                event.stopPropagation();
            }

            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const visible = this._visible();
        const at = visible.indexOf(item);
        const next = visible[at + step];

        if (undefined === next) {
            return;
        }

        this._place(item, next, 1 === step);
        this._focusable(item)?.focus({ preventScroll: true });
    }

    _dragStart(event) {
        const item = event.target.closest('[data-compose-action]');

        if (null === item || (0 !== event.button && 'mouse' === event.pointerType)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        this._dragEnd();
        this._drag = { item, pointerId: event.pointerId };
        item.classList.add(...DRAGGING);
        this._focusable(item)?.focus({ preventScroll: true });

        this._onPointerMove = (move) => this._dragMove(move);
        this._onPointerUp = () => this._dragEnd();
        window.addEventListener('pointermove', this._onPointerMove, true);
        window.addEventListener('pointerup', this._onPointerUp, true);
        window.addEventListener('pointercancel', this._onPointerUp, true);
    }

    /**
     * Where the pointer is among the other buttons decides where the dragged
     * one goes: before the first one it is above, or level with and left of
     * the middle of. Rows wrap while arranging, so the line counts first.
     */
    _dragMove(event) {
        if (undefined === this._drag || event.pointerId !== this._drag.pointerId) {
            return;
        }

        event.preventDefault();

        const others = this._visible().filter((item) => item !== this._drag.item);
        const before = others.find((item) => {
            const box = item.getBoundingClientRect();

            return event.clientY < box.top
                || (event.clientY <= box.bottom && event.clientX < box.left + box.width / 2);
        });

        if (undefined === before) {
            const last = others[others.length - 1];

            if (undefined !== last) {
                this._place(this._drag.item, last, true);
            }

            return;
        }

        this._place(this._drag.item, before, false);
    }

    _dragEnd() {
        if (undefined === this._drag) {
            return;
        }

        this._drag.item.classList.remove(...DRAGGING);
        this._drag = undefined;
        window.removeEventListener('pointermove', this._onPointerMove, true);
        window.removeEventListener('pointerup', this._onPointerUp, true);
        window.removeEventListener('pointercancel', this._onPointerUp, true);
    }

    /** Move `item` to just before (or after) `other`, in the whole order. */
    _place(item, other, after) {
        const key = item.dataset.composeAction;
        const order = this._order().filter((name) => name !== key);
        const at = order.indexOf(other.dataset.composeAction);

        order.splice(true === after ? at + 1 : at, 0, key);
        this._applyOrder(order);
    }

    // ── Order ─────────────────────────────────────────────────────────

    /** The buttons that take part in the order, drawn or not. */
    _sortables() {
        return Array.from(this.element.querySelectorAll(':scope > [data-compose-action]'));
    }

    /** The ones on screen now, in the order they stand. */
    _visible() {
        return this._byOrder(this._sortables()).filter((item) => this._focusable(item)?.getClientRects().length > 0);
    }

    _focusable(item) {
        return undefined === item ? null : this._trigger(item);
    }

    _byOrder(items) {
        return [...items].sort((a, b) => (Number(a.style.order) || 0) - (Number(b.style.order) || 0));
    }

    /** Every action name, in the order on screen; the default fills in the rest. */
    _order() {
        return this._full(this._byOrder(this._sortables()).map((item) => item.dataset.composeAction));
    }

    /** An order made whole, as ComposeAction::ordered() does on the server. */
    _full(order) {
        const whole = [];

        [...(order ?? []), ...this.defaultOrderValue].forEach((name) => {
            if ('string' === typeof name && false === whole.includes(name)) {
                whole.push(name);
            }
        });

        return whole;
    }

    _applyOrder(order) {
        const whole = this._full(order);

        this._sortables().forEach((item) => {
            item.style.order = String(whole.indexOf(item.dataset.composeAction) + 1);
        });

        if (true === this.hasMoreTarget) {
            this.moreTarget.style.order = String(whole.length + 1);
        }
    }
}

/** How a button looks while the bar is being arranged: movable, not pressable. */
const ARRANGING = ['outline-dashed', 'outline-1', 'outline-offset-[-1px]', 'outline-ink-faint', 'cursor-grab', 'touch-none', 'select-none'];

/** And the one being dragged. */
const DRAGGING = ['cursor-grabbing', 'opacity-60', 'bg-hover'];
