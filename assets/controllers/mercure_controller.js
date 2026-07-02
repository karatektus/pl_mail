import {Controller} from "@hotwired/stimulus";

export default class extends Controller {
    static values = {
        url: String,
    };

    connect() {
        this._es = new EventSource(this.urlValue, {withCredentials: true});

        this._es.onmessage = (event) => {
            console.log("[mercure] message received:", event.data);
            const data = JSON.parse(event.data);
            this._handleUpdate(data);
        };

        this._es.onerror = (error) => {
            console.error("[mercure] EventSource error:", error);
        };
    }

    disconnect() {
        if (this._es) {
            this._es.close();
        }
    }

    _handleUpdate(data) {
        if (data.type === "mailbox.synced") {
            this.dispatch("mailbox-synced", {detail: data});
        }
    }
}
