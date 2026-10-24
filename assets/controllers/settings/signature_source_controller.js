import { Controller } from "@hotwired/stimulus";

/**
 * The signature editor's second view: the same signature, as HTML source.
 *
 * The editor is a contenteditable, which is right for a signature somebody
 * types and useless for one they already have — out of a generator, a brand
 * guide, another mail client. Pasting that source into a contenteditable gets
 * you the source, angle brackets and all. So there is a textarea to paste it
 * into, and a pair of buttons to move between the two.
 *
 * THE TWO DIRECTIONS ARE NOT THE SAME ACT
 *
 * Rendered → HTML is local: the textarea is filled with the editor's markup.
 *
 * HTML → Rendered asks the server. The source is posted to the preview route,
 * which runs the import a save would run — the sanitiser, and fetching remote
 * images into the signature — and what comes back is what is shown. The point
 * is that the rendered view never flatters: a `<style>` block the server is
 * going to drop is already gone from what the user is looking at, and the line
 * under the editor says so by name. Rendering the pasted source in the browser
 * instead would show a signature that the next save quietly made worse.
 *
 * The hidden field the form posts follows whichever view is up. In the source
 * view it is the textarea's text, so pressing Save without rendering first
 * saves what was typed — through the same import, on the server.
 *
 * | Targets        |                                                        |
 * |----------------|--------------------------------------------------------|
 * | editor         | the contenteditable (shared with compose--compose-toolbar) |
 * | source         | the textarea                                           |
 * | field          | the hidden input the form posts                        |
 * | formatting     | the format buttons, which only act on the editor       |
 * | renderedButton | shows the rendered view                                |
 * | sourceButton   | shows the source view                                  |
 * | report         | what the last render removed, embedded or left remote  |
 *
 * Values:
 *   url    — the preview route
 *   token  — csrf_token() for that route
 *   i18n   — the report's sentences, translated server-side
 */
export default class extends Controller {
    static targets = ["editor", "source", "field", "formatting", "renderedButton", "sourceButton", "report"];

    static values = {
        url: String,
        token: String,
        i18n: { type: Object, default: {} },
    };

    showSource(event) {
        event?.preventDefault();

        if (false === this.sourceTarget.hidden) {
            return;
        }

        this.sourceTarget.value = this.editorTarget.innerHTML.trim();
        this.#show("source");
        this.sourceTarget.focus();
    }

    async showRendered(event) {
        event?.preventDefault();

        if (true === this.sourceTarget.hidden || true === this._rendering) {
            return;
        }

        this._rendering = true;
        this.renderedButtonTarget.disabled = true;

        try {
            const imported = await this.#preview(this.sourceTarget.value);

            // Already sanitised by the server — the same string a save would
            // store and the page would print back into this element.
            this.editorTarget.innerHTML = imported.html;
            this.fieldTarget.value = imported.html;
            this.#report(imported);
            this.#show("rendered");
        } catch {
            // Stay in the source view: the text is the only copy of what was
            // typed, and swapping it for a stale rendering would lose it.
            this.#say(this.i18nValue.failed ?? "");
        } finally {
            this._rendering = false;
            this.renderedButtonTarget.disabled = false;
        }
    }

    /** The source view is what gets saved while it is the one showing. */
    typed() {
        this.fieldTarget.value = this.sourceTarget.value;
    }

    // ── Private ───────────────────────────────────────────────────────────

    async #preview(html) {
        const body = new FormData();

        body.set("_token", this.tokenValue);
        body.set("signature", html);

        const response = await fetch(this.urlValue, {
            method: "POST",
            body,
            headers: { Accept: "application/json" },
            credentials: "same-origin",
        });

        if (false === response.ok) {
            throw new Error(`preview answered ${response.status}`);
        }

        return response.json();
    }

    #show(view) {
        const source = "source" === view;

        this.sourceTarget.hidden = false === source;
        this.editorTarget.hidden = source;
        this.formattingTarget.classList.toggle("invisible", source);
        this.sourceButtonTarget.setAttribute("aria-pressed", String(source));
        this.renderedButtonTarget.setAttribute("aria-pressed", String(false === source));

        if (true === source) {
            this.fieldTarget.value = this.sourceTarget.value;
        }
    }

    /**
     * One line, built from what the import reported. Elements are written as
     * tags and attributes as bare names, which is how they were spelled in the
     * source the user is being told about.
     */
    #report(imported) {
        const removed = [
            ...(imported.removedElements ?? []).map((name) => `<${name}>`),
            ...(imported.removedAttributes ?? []),
        ];
        const parts = [];

        parts.push(
            removed.length > 0
                ? (this.i18nValue.removed ?? "").replace("%items%", removed.join(", "))
                : (this.i18nValue.clean ?? ""),
        );

        if ((imported.embeddedImages ?? 0) > 0) {
            parts.push((this.i18nValue.embedded ?? "").replace("%count%", String(imported.embeddedImages)));
        }

        if ((imported.remoteImages ?? 0) > 0) {
            parts.push((this.i18nValue.remote ?? "").replace("%count%", String(imported.remoteImages)));
        }

        this.#say(parts.filter((part) => "" !== part).join(" "));
    }

    #say(text) {
        this.reportTarget.textContent = text;
        this.reportTarget.hidden = "" === text;
    }
}
