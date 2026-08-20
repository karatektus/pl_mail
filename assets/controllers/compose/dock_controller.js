import { Controller } from '@hotwired/stimulus';

/**
 * Say so when a compose window fails to open.
 *
 * Clicking "Compose" is a turbo-frame navigation, and Turbo's answer to a
 * response it cannot use is to write `<strong class="turbo-frame-error">Content
 * missing</strong>` into the frame and log to the console. Reproduced against a
 * stubbed 503 on `/compose/new`: that string — untranslated, four words of
 * Turbo's own English, tucked in the corner where the window should have
 * appeared — was the entire user-facing result. From the far side of the screen
 * where the button is, the click simply did nothing.
 *
 * That silence is the fault worth fixing regardless of what caused the 503: a
 * backend hiccup, a dropped connection, a session that expired while the tab
 * sat open. The window either opens or it says why not.
 *
 * Mounted on the dock wrapper rather than on the frame itself, because the
 * frame's contents are replaced wholesale by every navigation — a controller on
 * the frame would be torn down by the very swap it needs to watch.
 */
export default class extends Controller {
    static values = { i18n: Object };

    connect() {
        this._boundMissing = this._onMissing.bind(this);
        this._boundError   = this._onError.bind(this);

        // `turbo:frame-missing` is what a 4xx/5xx body with no matching frame
        // raises; `turbo:fetch-request-error` is the network never answering at
        // all. Both look identical from the button, so both land here.
        this.element.addEventListener('turbo:frame-missing', this._boundMissing);
        this.element.addEventListener('turbo:fetch-request-error', this._boundError);
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-missing', this._boundMissing);
        this.element.removeEventListener('turbo:fetch-request-error', this._boundError);
    }

    _onMissing(event) {
        // Claim it, or Turbo writes its own "Content missing" over ours.
        event.preventDefault();

        const status = event.detail?.response?.status;

        this._render(
            status === 401 || status === 403
                ? this._t('signedOut', 'Your session has expired. Reload the page and sign in again.')
                : this._t('failed', 'The compose window could not be opened. Please try again.'),
        );
    }

    _onError() {
        this._render(this._t('offline', 'The compose window could not be opened — you appear to be offline.'));
    }

    _t(key, fallback) {
        return this.i18nValue?.[key] ?? fallback;
    }

    /**
     * A card in the dock, shaped like the window that failed to arrive, so the
     * failure appears where the user was looking.
     *
     * `role="alert"` because nothing about this is visible to a screen reader
     * otherwise — the click produced no focus change and no navigation.
     */
    _render(message) {
        const frame = this.element.querySelector('turbo-frame#compose_dock');

        if (null === frame) {
            return;
        }

        frame.replaceChildren();

        const card = document.createElement('div');
        card.setAttribute('role', 'alert');
        // The same entrance the window it stands in for would have played.
        // This card is the answer to a click that otherwise did nothing at all,
        // which is the failure this whole controller exists for — appearing
        // without a sound is how it got missed in the first place. Set as an
        // attribute rather than animated here because motion.css owns every
        // duration in the app; the observer in motion.js picks it up on append.
        card.setAttribute('data-enter', 'fade');
        card.className =
            'w-80 rounded-xl border border-line bg-surface shadow-lg px-4 py-3 ' +
            'text-sm text-ink flex items-start gap-3';

        const icon = document.createElement('i');
        icon.className = 'fa-solid fa-triangle-exclamation text-danger mt-0.5';
        icon.setAttribute('aria-hidden', 'true');

        const text = document.createElement('span');
        text.className = 'flex-1';
        text.textContent = message;

        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'text-ink-faint hover:text-ink transition-colors';
        dismiss.setAttribute('aria-label', this._t('dismiss', 'Dismiss'));
        dismiss.textContent = '×';
        dismiss.addEventListener('click', () => frame.replaceChildren());

        card.append(icon, text, dismiss);
        frame.appendChild(card);
    }
}
