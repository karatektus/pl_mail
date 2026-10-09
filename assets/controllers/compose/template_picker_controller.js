import { Controller } from "@hotwired/stimulus";
import { requestFailed } from "../../request_errors.js";
import { showToast } from "../../toast.js";
import { chipify, describe, markersAsTokens } from "../../compose/template_variables.js";

/**
 * The compose window's template picker: load the list, preview a row, insert
 * the one that is chosen, and save the message on screen as a new template.
 *
 * Sits on the same element as `ui--dropdown`, which owns open and closed; this
 * controller is told when the menu opened and fills it. The inserting itself
 * is compose--compose#insertTemplate — the window owns its body, its signature
 * block and its recipients, and a second controller reaching into those would
 * be a second set of rules about them.
 *
 * WHAT THE SERVER RENDERS AND WHAT IT DOES NOT. Choosing a row asks the server
 * for that template rendered against the address currently in From: dates,
 * sender and signature filled in, recipient variables left open for the window
 * to fill (App\Service\Template\TemplateRenderer). The preview does not ask
 * the server anything — it shows the stored template with its variables drawn
 * as chips, from markup the panel already carries.
 *
 * Values
 *   panelUrl  String  GET ?account=<From token> → the panel fragment
 *   saveUrl   String  POST {subject, body, account} → {ok, message}
 *   i18n      Object  loading, failed, nothingToSave
 *
 * Targets
 *   panel                         the dropdown's menu, which the fragment fills
 *   root, search, item, group,    declared by compose/_template_panel.html.twig
 *   noMatch, previewSubject,
 *   previewBody
 */
export default class extends Controller {
    static targets = ["panel", "root", "search", "item", "group", "noMatch", "previewSubject", "previewBody"];

    static values = {
        panelUrl: String,
        saveUrl: String,
        i18n: Object,
    };

    /**
     * Fetch the list. Every time the menu opens, not once: a template saved a
     * moment ago has to be in it, and the grouping follows whichever address
     * is in From now.
     */
    async load() {
        this.panelTarget.innerHTML = "";
        this.panelTarget.append(this.#note(this.i18nValue.loading ?? ""));

        try {
            const url = new URL(this.panelUrlValue, window.location.origin);
            url.searchParams.set("account", this.#fromToken());

            const response = await fetch(url, { headers: { Accept: "text/html" } });

            if (false === response.ok) {
                throw new Error(String(response.status));
            }

            this.panelTarget.innerHTML = await response.text();
        } catch {
            this.panelTarget.innerHTML = "";
            this.panelTarget.append(this.#note(this.i18nValue.failed ?? ""));

            return;
        }

        if (this.hasItemTarget) {
            this.#show(this.itemTargets[0]);
        }

        // Typing straight away is how a long list is used.
        if (this.hasSearchTarget && false === window.matchMedia("(max-width: 767px)").matches) {
            this.searchTarget.focus();
        }
    }

    /** Narrow the list to the rows whose name contains what was typed. */
    filter() {
        const wanted = this.searchTarget.value.trim().toLowerCase();
        let shown = 0;

        this.itemTargets.forEach((item) => {
            const match = "" === wanted || (item.dataset.name ?? "").includes(wanted);

            item.parentElement.hidden = false === match;
            shown += true === match ? 1 : 0;
        });

        // A heading over nothing is a heading for something that is not there.
        this.groupTargets.forEach((group) => {
            group.hidden = null === group.querySelector("li:not([hidden])");
        });

        if (this.hasNoMatchTarget) {
            this.noMatchTarget.hidden = 0 !== shown;
        }
    }

    preview(event) {
        this.#show(event.currentTarget);
    }

    /** Insert the chosen template and close. */
    async choose(event) {
        const composer = this.#composer();

        if (null === composer) {
            return;
        }

        const url = new URL(event.currentTarget.dataset.url, window.location.origin);
        url.searchParams.set("account", this.#fromToken());

        let payload = null;

        try {
            const response = await fetch(url, { headers: { Accept: "application/json" } });

            payload = response.ok ? await response.json() : null;
        } catch {
            payload = null;
        }

        if (true !== payload?.ok) {
            showToast(this.i18nValue.failed ?? "", { type: "error" });

            return;
        }

        this.#dropdown()?.close();
        composer.insertTemplate({ subject: payload.subject ?? "", html: payload.html ?? "" });
    }

    /**
     * Keep the message on screen as a template.
     *
     * What is sent is not the body as it stands. The window's signature block
     * becomes a `{{signature}}` variable — otherwise the template would carry
     * today's signature as fixed text and collect a second one on every use —
     * the quoted original under a reply is left out, because it is somebody
     * else's mail, and a variable still open goes back to being its token.
     * See ComposeTemplateController::saveDraft().
     */
    async saveDraft() {
        const scope = this.#scope();

        if (null === scope) {
            return;
        }

        const plain = scope.querySelector("[data-compose--compose-target='plainBody']");
        const editor = scope.querySelector("[data-compose--compose-toolbar-target='editor']");
        let body = "";

        if (null !== plain && false === plain.disabled) {
            const holder = document.createElement("div");

            holder.textContent = plain.value;
            body = holder.innerHTML.replace(/\n/g, "<br>");
        } else if (null !== editor) {
            const clone = markersAsTokens(editor);

            clone.querySelectorAll("[data-quote-wrapped], [data-quoted], [data-quote-toggle], blockquote")
                .forEach((quote) => quote.remove());

            clone.querySelectorAll("[data-pl-signature]").forEach((signature) => {
                const paragraph = document.createElement("p");

                paragraph.textContent = "{{signature}}";
                signature.replaceWith(paragraph);
            });

            body = "" === clone.textContent.trim() ? "" : clone.innerHTML;
        }

        if ("" === body) {
            showToast(this.i18nValue.nothingToSave ?? "", { type: "info" });

            return;
        }

        try {
            const response = await fetch(this.saveUrlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-Token": this.hasRootTarget ? this.rootTarget.dataset.csrf ?? "" : "",
                },
                body: JSON.stringify({
                    subject: scope.querySelector("[data-compose--compose-target='subject']")?.value ?? "",
                    body,
                    account: this.#fromToken(),
                }),
            });

            if (false === response.ok) {
                throw new Error(String(response.status));
            }

            showToast((await response.json()).message ?? "", { type: "success" });
            this.#dropdown()?.close();
        } catch {
            requestFailed();
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    /** Put one row's template in the preview pane, variables as chips. */
    #show(item) {
        if (false === this.hasPreviewBodyTarget || false === this.hasRootTarget) {
            return;
        }

        const words = JSON.parse(this.rootTarget.dataset.chipLabels ?? "{}");
        const known = Object.keys(words.labels ?? {});
        const draw = (token) => describe(token, words);

        this.previewSubjectTarget.textContent = item.dataset.subject ?? "";
        chipify(this.previewSubjectTarget, known, draw);

        // From the row's <template>: parsed, never rendered until it is here.
        this.previewBodyTarget.innerHTML = item.querySelector("template")?.innerHTML ?? "";
        chipify(this.previewBodyTarget, known, draw);
    }

    #note(text) {
        const note = document.createElement("p");

        note.className = "px-3 py-6 text-center text-sm text-ink-faint";
        note.textContent = text;

        return note;
    }

    /** The compose window this picker belongs to. */
    #scope() {
        return this.element.closest("[data-controller~='compose--compose']");
    }

    #composer() {
        const scope = this.#scope();

        return null === scope
            ? null
            : this.application.getControllerForElementAndIdentifier(scope, "compose--compose");
    }

    #dropdown() {
        return this.application.getControllerForElementAndIdentifier(this.element, "ui--dropdown");
    }

    /** The `accountId|address` token of the address currently in From. */
    #fromToken() {
        return this.#scope()?.querySelector("[data-compose--compose-target='accountSelect']")?.value ?? "";
    }
}
