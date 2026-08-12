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
        if (null !== this._picker || false === this.hasPanelTarget) {
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
