import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";

/**
 * "Help me write this" — the composer's end of it.
 *
 * WHY THE SERVER MAKES THE CALL
 * ─────────────────────────────
 * `connect-src 'self'` is enforced, so this could not reach the model host even
 * if it wanted to — and should not want to: the host is an address on the
 * operator's private network, and putting it in a page hands it to every script
 * that page loads. This posts to plMail and plMail asks.
 *
 * WHY THE TEXT GOES IN AS A TEXT NODE
 * ───────────────────────────────────
 * Nothing generated is HTML. A styled wrapper around it would be subtracted by
 * the composer's typed-length calculation — which ignores blockquotes, quote
 * wrappers and bordered divs — and a draft whose only content was "not typed"
 * stops autosaving. A plain text node is what a person would have produced by
 * typing, and behaves like it everywhere downstream.
 *
 * WHY IT NEVER SILENTLY REPLACES ANYTHING
 * ───────────────────────────────────────
 * A rewrite returns a new version of what somebody wrote. Overwriting the
 * original with it means a model deleted their words with no undo they can see,
 * so the answer is always INSERTED and the original always stays. Deciding
 * which to keep is the writer's job and takes one keystroke.
 */
export default class extends Controller {
    static values = {
        url: String,
        /** The message being replied to, so a reply can be drafted from it. */
        inReplyTo: { type: String, default: "" },
        busyLabel: { type: String, default: "…" },
        failedLabel: { type: String, default: "No answer" },
    };

    static targets = ["status"];

    /** One at a time. A cold model takes seconds and the button invites clicking again. */
    #inFlight = false;

    /**
     * @param {PointerEvent & {params: {task: string}}} event
     */
    async run(event) {
        event.preventDefault();

        if (true === this.#inFlight) return;

        const task = event.params?.task;
        if (!task) return;

        const editor = this.#editor();
        if (null === editor) return;

        this.#inFlight = true;
        this.#say(this.busyLabelValue);

        try {
            const body = new URLSearchParams({
                task,
                draft: this.#plainText(editor),
                subject: this.#subject(),
                inReplyTo: this.inReplyToValue,
            });

            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: jsonCsrfHeaders({ "Content-Type": "application/x-www-form-urlencoded" }),
                body,
            });

            if (false === response.ok) {
                this.#say(this.failedLabelValue);
                return;
            }

            const { text } = await response.json();

            if ("string" !== typeof text || "" === text.trim()) {
                this.#say(this.failedLabelValue);
                return;
            }

            this.#insert(editor, text);
            this.#say("");
        } catch {
            // A model host that is off, a proxy that timed out, a network that
            // went away. None of it is worth a stack trace at somebody who is
            // trying to write an email.
            this.#say(this.failedLabelValue);
        } finally {
            this.#inFlight = false;
        }
    }

    /**
     * Append, on its own line, and never overwrite.
     *
     * Deliberately at the END rather than at the caret. The caret is wherever
     * the editor was last focused, and opening this menu moved focus — so
     * "at the caret" is a position the writer did not choose. The end of the
     * body is somewhere they can see, and it is where a draft grows anyway.
     */
    #insert(editor, text) {
        const block = document.createElement("div");

        for (const [index, line] of text.split(/\r?\n/).entries()) {
            if (index > 0) block.appendChild(document.createElement("br"));
            block.appendChild(document.createTextNode(line));
        }

        editor.appendChild(block);

        // The composer autosaves on input, and a scripted mutation raises none —
        // so without this the generated text sits on screen and is not in the
        // draft, and closing the window loses it.
        editor.dispatchEvent(new Event("input", { bubbles: true }));

        block.scrollIntoView({ block: "nearest" });
    }

    #editor() {
        return this.element.closest("[data-controller~='compose--compose']")
            ?.querySelector("[data-compose--toolbar-target='editor']") ?? null;
    }

    #subject() {
        return this.element.closest("[data-controller~='compose--compose']")
            ?.querySelector("input[name='subject']")?.value ?? "";
    }

    /** What the writer has actually written, without the markup around it. */
    #plainText(editor) {
        return (editor.innerText ?? editor.textContent ?? "").trim();
    }

    #say(message) {
        if (true === this.hasStatusTarget) {
            this.statusTarget.textContent = message;
        }
    }
}
