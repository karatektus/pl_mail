import { Controller } from '@hotwired/stimulus';

/**
 * Search and paging for the queue backlog panel.
 *
 * The list is a fixed-height scroller holding one page at a time; reaching the
 * end fetches the next. Filtering re-queries the server rather than hiding
 * rows, because the interesting message is usually one of the thousands that
 * were never fetched — a client-side filter over the loaded page would answer
 * a question nobody asked.
 *
 * While the panel is filtered or scrolled past the first page it holds the
 * frame's auto-refresh: a refresh re-renders from server state and would throw
 * away the filter and every appended page mid-read.
 */
export default class extends Controller {
    static targets = ['input', 'list', 'scroller', 'count', 'empty', 'loading'];

    static values = {
        url: String,
        perPage: { type: Number, default: 25 },
        total: { type: Number, default: 0 },
        /** Translated "%shown% of %total%", placeholders still in it. */
        countTemplate: { type: String, default: '%shown% / %total%' },
    };

    /** Pixels from the bottom at which the next page is fetched. */
    static NEAR_END = 120;

    #offset = 0;
    #held = false;
    #loading = false;
    #exhausted = false;
    #debounce = null;

    connect() {
        this.#offset = this.listTarget.querySelectorAll('[data-queue-row]').length;
        this.#exhausted = this.#offset >= this.totalValue;
    }

    disconnect() {
        clearTimeout(this.#debounce);

        // The hold is a counter on the refresh controller, so an unreleased
        // hold stops the whole panel refreshing for as long as the page lives.
        this.#release();
    }

    filter() {
        clearTimeout(this.#debounce);
        this.#debounce = setTimeout(() => this.#reload(), 250);
    }

    onScroll() {
        const scroller = this.scrollerTarget;
        const remaining = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight;

        if (remaining <= this.constructor.NEAR_END) {
            this.#loadMore();
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    /** Filter changed: page one again, replacing what is on screen. */
    async #reload() {
        const rows = await this.#fetch(0);

        if (null === rows) {
            return;
        }

        this.listTarget.innerHTML = rows.html;
        this.#offset = rows.returned;
        this.#exhausted = rows.returned >= rows.total || 0 === rows.returned;
        this.scrollerTarget.scrollTop = 0;

        this.#renderCount(rows.total);
        this.#syncHold();
    }

    async #loadMore() {
        if (this.#loading || this.#exhausted) {
            return;
        }

        const rows = await this.#fetch(this.#offset);

        if (null === rows) {
            return;
        }

        this.listTarget.insertAdjacentHTML('beforeend', rows.html);
        this.#offset += rows.returned;
        this.#exhausted = 0 === rows.returned || this.#offset >= rows.total;

        this.#renderCount(rows.total);
        this.#syncHold();
    }

    /**
     * @returns {Promise<{html: string, returned: number, total: number}|null>}
     *          null when the request failed or one was already in flight —
     *          a queue panel that cannot reach the server should keep showing
     *          what it last knew rather than emptying itself.
     */
    async #fetch(offset) {
        if (this.#loading) {
            return null;
        }

        this.#loading = true;
        this.#toggleLoading(true);

        try {
            const url = new URL(this.urlValue, window.location.origin);
            url.searchParams.set('q', this.#query());
            url.searchParams.set('offset', String(offset));

            const response = await fetch(url, { headers: { Accept: 'text/html' } });

            if (false === response.ok) {
                return null;
            }

            const markup = document.createRange().createContextualFragment(await response.text());
            const meta = markup.querySelector('[data-queue-meta]');
            const returned = Number(meta?.dataset.returned ?? 0);
            const total = Number(meta?.dataset.total ?? 0);

            meta?.remove();

            const container = document.createElement('div');
            container.append(markup);

            return { html: container.innerHTML, returned, total };
        } catch {
            return null;
        } finally {
            this.#loading = false;
            this.#toggleLoading(false);
        }
    }

    #query() {
        return this.hasInputTarget ? this.inputTarget.value.trim() : '';
    }

    #renderCount(total) {
        this.totalValue = total;

        if (this.hasEmptyTarget) {
            this.emptyTarget.classList.toggle('hidden', this.#offset > 0);
        }

        if (false === this.hasCountTarget) {
            return;
        }

        // The translated string arrives with its placeholders intact, so the
        // count can be re-rendered per page without a round trip and without
        // this controller knowing which language it is in.
        this.countTarget.textContent = this.countTemplateValue
            .replace('%shown%', String(this.#offset))
            .replace('%total%', String(total));
    }

    #toggleLoading(active) {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.toggle('hidden', false === active);
        }
    }

    /** Held while the panel shows anything other than an unfiltered page one. */
    #syncHold() {
        const dirty = '' !== this.#query() || this.#offset > this.perPageValue;

        if (dirty) {
            this.#hold();
            return;
        }

        this.#release();
    }

    #hold() {
        if (this.#held) {
            return;
        }

        this.#held = true;
        this.dispatch('holding', { bubbles: true });
    }

    #release() {
        if (false === this.#held) {
            return;
        }

        this.#held = false;
        this.dispatch('released', { bubbles: true });
    }
}
