import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";
import { requestFailed } from "../../request_errors.js";

/*
 * One after the other, across every step on the page. The server's answer carries
 * the task's count, and two ticks answered out of order would leave the older count
 * on screen. It is out here because a tick outlives its button: the pane can redraw
 * while one is on its way.
 */
let saving = Promise.resolve();

/** Everywhere the step is on screen: its row, and its dialog if that is open. */
function show(id, done) {
    document.querySelectorAll(`[data-task-step-tick="${id}"]`).forEach((tick) => {
        tick.setAttribute("aria-checked", done ? "true" : "false");
    });
}

async function save(id, url, token, done) {
    const body = new FormData();
    body.append("_token", token);
    body.append("done", done ? "1" : "0");

    let response = null;

    try {
        response = await fetch(url, {
            method: "POST",
            body,
            headers: { "X-Requested-With": "XMLHttpRequest" },
        });
    } catch {
        // Handled below with response still null.
    }

    // Anything but the stream was not this action answering, however it ended: an
    // expired session is a login page with a 200.
    if (true === response?.ok && false === (response.headers.get("Content-Type") ?? "").includes("turbo-stream")) {
        response = new Response(null, { status: 403 });
    }

    if (requestFailed(response)) {
        show(id, !done);

        return;
    }

    // Again, since the pane may have redrawn from before this was saved.
    show(id, done);
    Turbo.renderStreamMessage(await response.text());
}

/**
 * A step's tick: on screen at once, with the saving behind it.
 *
 * A fetch rather than a form, because of what a frame does with one. It stops the
 * submission still running when the next one starts, so working down a list quickly
 * would call off ticks that had already been shown as done. It also means the pane
 * and the dialog around this stay as they are, and anything typed there with them.
 *
 * The confirmation comes in the answer, so there is none until the save happened. A
 * save that fails takes the tick back, and requestFailed() says so.
 *
 * Values:
 *   id     Number  the step
 *   url    String  POST done=1|0
 *   token  String  CSRF for url
 */
export default class extends Controller {
    static values = { id: Number, url: String, token: String };

    tick() {
        const done = "true" !== this.element.getAttribute("aria-checked");
        const { idValue: id, urlValue: url, tokenValue: token } = this;

        show(id, done);

        saving = saving.then(() => save(id, url, token, done));
    }
}
