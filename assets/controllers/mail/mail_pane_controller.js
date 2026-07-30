import { Controller } from "@hotwired/stimulus";

// MailboxSpecialUse values (see App\Domain\Enum\MailboxSpecialUse) mapped to
// the data-sync-scope tokens used by the list templates.
/** The frame wrapping the list itself — see templates/_layout/_mailbox.html.twig. */
const LIST_FRAME_ID = "inbox-list-frame";

const SYNC_SCOPES = {
    "\\Inbox": "inbox",
    "\\Sent": "sent",
    "\\Trash": "trash",
    "\\Drafts": "drafts",
    "\\Junk": "junk",
    "\\Archive": "archive",
};

export default class extends Controller {
    static targets = ["list", "reading"];
    static values = { open: Boolean , mailBoxId: Number};

    connect() {
        console.log("[mail-pane] connected");
        this._listUrl = this.openValue ? null : window.location.href;
        this._onPopState = this._handlePopState.bind(this);
        window.addEventListener("popstate", this._onPopState);

        // Restore correct visual state on direct load / refresh
        if (this.openValue) {
            this._showReading();
        } else {
            this._showList();
        }
    }
    disconnect() {
        window.removeEventListener("popstate", this._onPopState);
    }

    async open(event) {
        event.preventDefault();

        const link = event.currentTarget;
        const url = link.href;

        // Remember where to go back to, if we don't already know.
        if (!this._listUrl) {
            this._listUrl = window.location.href;
        }

        await this._loadMessage(url);

        history.pushState({ mailPaneOpen: true }, "", url);
    }

    close(event) {
        if (event) {
            event.preventDefault();
        }

        this._showList();

        if (this._listUrl) {
            history.pushState({ mailPaneOpen: false }, "", this._listUrl);
        } else {
            history.back();
        }
    }

    async _handlePopState(event) {
        const state = event.state;

        if (state && state.mailPaneOpen) {
            await this._loadMessage(window.location.href);
        } else {
            this._showList();
        }
    }

    async _loadMessage(url) {
        const response = await fetch(url, {
            headers: { "X-Requested-With": "fetch" },
        });

        if (!response.ok) {
            window.location.href = url; // fall back to a real navigation on failure
            return;
        }

        const html = await response.text();
        this.readingTarget.innerHTML = html;
        this._showReading();
    }

    _showReading() {
        this.listTarget.classList.add("hidden");
        this.readingTarget.classList.remove("hidden");
    }

    _showList() {
        this.readingTarget.classList.add("hidden");
        this.listTarget.classList.remove("hidden");
    }

    onMailboxSynced(event) {
        const data = event.detail;

        // The list views are unified across accounts, so a synced mailbox is
        // relevant when its special-use role matches what the view shows.
        // Views that span every mailbox (label, search, starred) use "*".
        if (this._affectsCurrentView(data)) {
            this._refreshList();
        }
    }

    _affectsCurrentView(data) {
        if (!this.hasListTarget) {
            return false;
        }

        const scope = this.listTarget.dataset.syncScope || "*";
        if (scope === "*") {
            return true;
        }

        return scope.split(" ").includes(SYNC_SCOPES[data.specialUse] ?? "");
    }

    /**
     * Refresh the list after a sync, and nothing else.
     *
     * Fetched and swapped by hand rather than through Turbo. A page visit
     * replaces the whole document and takes an open dialog, a half-typed form
     * and the compose window with it — which is how connecting a mail account
     * kept destroying the setup wizard it was connected from, since the account
     * triggers the sync that triggers this.
     *
     * `frame.reload()` looked like the scoped answer and is not: this frame is
     * server-rendered with no `src`, so Turbo has nothing to re-fetch and falls
     * back to reloading the page — the very thing being avoided, and with
     * `data-turbo-action="advance"` on the frame it navigates too.
     *
     * So: ask for the current URL, take the matching frame out of the response,
     * and swap its contents in. It cannot navigate, because nothing here
     * navigates.
     *
     * The sidebar keeps its own counts up to date from the same Mercure
     * updates, so nothing outside the frame needs this to redraw it.
     */
    async _refreshList() {
        const frame = document.getElementById(LIST_FRAME_ID);

        if (frame === null) {
            console.warn("[mail-pane] no list frame to refresh");

            return;
        }

        // One at a time: a burst of sync events would otherwise have several
        // responses racing to write the same element.
        if (this._refreshing === true) {
            return;
        }

        this._refreshing = true;

        try {
            const response = await fetch(window.location.href, {
                headers: { "Turbo-Frame": LIST_FRAME_ID, Accept: "text/html" },
                credentials: "same-origin",
            });

            if (response.ok === false) {
                return;
            }

            const fresh = new DOMParser()
                .parseFromString(await response.text(), "text/html")
                .getElementById(LIST_FRAME_ID);

            if (fresh === null) {
                console.warn("[mail-pane] no list frame in the response");

                return;
            }

            frame.innerHTML = fresh.innerHTML;
            console.log("[mail-pane] list refreshed");
        } catch (error) {
            // A failed refresh is not worth surfacing: the next sync event, or
            // the next navigation, redraws it anyway.
            console.warn("[mail-pane] list refresh failed", error);
        } finally {
            this._refreshing = false;
        }
    }
}
