// assets/controllers/compose/emoji_picker_controller.js
import { Controller } from '@hotwired/stimulus';
import 'emoji-picker-element';

/**
 * The full emoji set behind the compose window's smiley button.
 *
 * `emoji-picker-element` is a self-contained web component — categories,
 * search, skin tones, recents and keyboard navigation are already in it — so
 * this controller only has to do the three things it cannot know about:
 *
 *   • point it at data we ship ourselves. Its default source is a jsdelivr
 *     URL, and this app fetches nothing from a CDN at runtime. The locale's
 *     JSON is vendored under assets/emoji and handed in as a value.
 *   • translate it. The component carries English labels and takes a
 *     replacement object; Twig fills that object from the same catalogue as
 *     the rest of the window, so the picker is German in a German window.
 *   • put the chosen character where the cursor was, which is the toolbar
 *     controller's job because the toolbar is what keeps the saved range.
 *
 * The picker is built on first open rather than on connect. Every compose
 * window would otherwise parse 400 KB of emoji data on a page that may never
 * have the button pressed — and a reply box opens on every thread.
 */
export default class extends Controller {
    static targets = ['panel'];

    static values = {
        // Vendored emojibase JSON for the active locale, handed in already
        // digested by the asset mapper.
        dataSource: String,
        // The picker's own locale, so its database and its search index match
        // the data file above.
        locale: { type: String, default: 'en' },
        // Every label inside the component, translated server-side.
        i18n: { type: Object, default: {} },
    };

    connect() {
        this._picker = null;
        this._boundClick = this._handleClick.bind(this);
    }

    disconnect() {
        this._picker?.removeEventListener('emoji-click', this._boundClick);
        this._picker = null;
    }

    /**
     * Called from the dropdown's `opened` event — the panel is visible by the
     * time this runs, which is what the component needs to size its grid.
     */
    build() {
        if (false === this.hasPanelTarget) {
            return;
        }

        // Re-measured on EVERY open, not only the first. The window can be
        // expanded, restored or rotated between two presses of the button, and
        // a cap worked out against the height it had last time is the same bug
        // in slower motion.
        if (null !== this._picker) {
            this._fitToWindow();

            return;
        }

        const picker = document.createElement('emoji-picker');

        picker.setAttribute('locale', this.localeValue);

        if ('' !== this.dataSourceValue) {
            picker.setAttribute('data-source', this.dataSourceValue);
        }

        if (Object.keys(this.i18nValue).length > 0) {
            picker.i18n = this.i18nValue;
        }

        picker.addEventListener('emoji-click', this._boundClick);

        this.panelTarget.appendChild(picker);
        this._picker = picker;

        this._styleShadow(picker);
        this._fitToWindow();
    }

    /**
     * Two corrections that can only be made from inside the shadow root.
     *
     * The component's root is open, which is the supported way to reach it —
     * page CSS cannot select into it and neither of these is exposed as a part
     * or a custom property.
     *
     *  • The grid's scrollbar. `scrollbar-color` was themed and reached it by
     *    inheritance, but `scrollbar-width` was never set, so the grid kept a
     *    15px native bar WITH STEPPER ARROWS inside a panel where every other
     *    scroller in the app is a thin overlay — themed the right colour and
     *    the wrong size, which reads worse than not having tried. Declared here
     *    rather than only on the host because something in the component's own
     *    sheet re-establishes it, so inheriting from outside is not enough.
     *  • The skin-tone button, which sat 1px off the panel's own outline and so
     *    appeared to be resting on it. It is given the same gutter as the rest
     *    of the row.
     */
    _styleShadow(picker) {
        const root = picker.shadowRoot;

        if (null === root || undefined === root) {
            return;
        }

        const sheet = document.createElement('style');

        sheet.textContent = `
            .tabpanel, .emoji-menu, [role="menu"] {
                scrollbar-width: thin;
            }

            .skintone-button-wrapper {
                margin-right: 0.375rem;
            }
        `;

        root.appendChild(sheet);
    }

    /**
     * Keep the panel inside the window it belongs to.
     *
     * It opens upward out of a compose window that is itself anchored to the
     * bottom of the screen, and its height was capped against the VIEWPORT
     * (`50vh`) — which knows nothing about where the window's top edge is. On a
     * 900px viewport with a 512px window that put the panel's top edge 33px
     * above the window's own, so an emoji panel belonging to the composer was
     * drawn outside it. Still on screen, and still wrong: it reads as a
     * detached menu.
     *
     * Measured rather than guessed because the window's height is not a
     * constant — it changes when the window is expanded, and again on a phone
     * where the window IS the screen. The cap is written as a custom property
     * so the CSS keeps its own ceiling and this only ever lowers it.
     */
    _fitToWindow() {
        // After a frame, because on the first open this runs in the same tick
        // as the panel being revealed and the picker being appended: the panel
        // has no laid-out box yet, the measurement comes out at or below zero,
        // and the cap is skipped — which looks exactly like the bug it fixes.
        requestAnimationFrame(() => {
            const host  = this.element.closest('.compose-window');
            const panel = this.hasPanelTarget ? this.panelTarget : null;

            if (null === host || null === panel) {
                return;
            }

            // The panel's bottom edge is pinned to the top of the toolbar
            // (`bottom-full`), so the room it has is everything between there
            // and the top of the window — less a margin, so it does not sit
            // flush against the window's own edge.
            const room = panel.getBoundingClientRect().bottom
                - host.getBoundingClientRect().top
                - 16;

            if (room > 0) {
                panel.style.setProperty('--emoji-max-height', `${Math.round(room)}px`);
            }
        });
    }

    _handleClick(event) {
        // `unicode` is the composed character including any chosen skin tone;
        // `emoji.unicode` is the base one, which would silently drop it.
        const emoji = event.detail?.unicode;

        if (undefined === emoji) {
            return;
        }

        this._toolbar()?.insertEmoji(emoji);
    }

    _toolbar() {
        const host = this.element.closest('[data-controller~="compose--compose-toolbar"]');

        if (null === host) {
            return null;
        }

        return this.application.getControllerForElementAndIdentifier(
            host,
            'compose--compose-toolbar',
        );
    }
}
