import { Controller } from "@hotwired/stimulus";

// MailboxSpecialUse values (see App\Domain\Enum\MailboxSpecialUse) mapped to
// the data-sync-scope tokens used by the list templates.
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

    _refreshList() {
        console.log("[mail-pane] refreshing list");
        Turbo.visit(window.location.href, { action: "replace" });
    }
}
