import { Controller } from "@hotwired/stimulus";
import { readFrames } from "../../ai/ndjson.js";

/**
 * One message's translation (#713): asking for it, watching it arrive, and the
 * one button that switches between it and the original.
 *
 * The body template draws everything: the bar that says this is a model's
 * translation and not the mail, the translation itself, and the original
 * wrapped beside it. This only shows and hides them and fills in text. A
 * stored translation is already on the page and already shown; nothing is
 * fetched for it.
 *
 * TEXT ONLY. The translation is model output derived from somebody's mail, so
 * it is written with textContent and never as HTML, like the summary's.
 *
 * The request comes from the message's ⋮ menu (mail--message-actions#translate),
 * which dispatches on the document with the message id; every body on the page
 * hears it, and only the one it names answers.
 *
 * A reader who leaves while it is being written does not stop it: the server
 * finishes and stores it, and the message opens translated next time.
 */
export default class extends Controller {
    static targets = ["bar", "pending", "headline", "status", "partial", "quote", "stale", "toggle", "again", "translation", "original"];

    static values = {
        message: Number,
        url: String,
        token: String,
        shown: { type: String, default: "original" },
        generatedLabel: String,
        generatedFromLabel: String,
        fromLabel: String,
        originalLabel: String,
        showOriginalLabel: String,
        showTranslationLabel: String,
        workingLabel: String,
        waitingLabel: String,
        readingLabel: String,
        errorLabels: Object,
    };

    #controller = null;
    #run = 0;
    #answer = "";

    disconnect() {
        this.#controller?.abort();
        this.#controller = null;
    }

    /** From the ⋮ menu: translate this message, or show the translation it already has. */
    request(event) {
        if (event.detail?.id !== this.messageValue) return;

        if (null === this.#controller && "" !== this.translationTarget.textContent.trim()) {
            this.#show("translation");

            return;
        }

        this.#start();
    }

    /** Translate again: after the message changed, or after a failure. */
    again() {
        this.#start();
    }

    /** The one click between the translation and the original. */
    toggle() {
        this.#show("translation" === this.shownValue ? "original" : "translation");
    }

    /**
     * Show the translation or the original, and make the bar say which. With
     * no translation there is nothing to switch to: the original, and a bar
     * that only carries whatever the status line says.
     */
    #show(which) {
        const has = "" !== this.translationTarget.textContent.trim();
        const translation = has && "translation" === which;

        this.shownValue = translation ? "translation" : "original";
        this.barTarget.hidden = false;
        this.translationTarget.hidden = false === translation;
        this.originalTarget.hidden = translation;
        this.headlineTarget.textContent = false === has ? "" : (translation ? this.#generated() : this.originalLabelValue);
        this.headlineTarget.hidden = false === has;
        this.toggleTarget.textContent = translation ? this.showOriginalLabelValue : this.showTranslationLabelValue;
        this.toggleTarget.hidden = false === has;
    }

    /**
     * What the bar says over the translation: the language the mail is in when
     * the model named it, and only then.
     */
    #generated() {
        return "" === this.fromLabelValue
            ? this.generatedLabelValue
            : this.generatedFromLabelValue.replace("%source%", this.fromLabelValue);
    }

    async #start() {
        this.#controller?.abort();

        const run = ++this.#run;

        this.#controller = new AbortController();
        this.#answer = "";

        this.barTarget.hidden = false;
        this.headlineTarget.textContent = this.generatedLabelValue;
        this.headlineTarget.hidden = false;
        this.againTarget.hidden = true;
        this.staleTarget.hidden = true;
        this.partialTarget.hidden = true;
        this.quoteTarget.hidden = true;
        this.toggleTarget.hidden = true;
        this.pendingTarget.hidden = false;
        this.#say(this.workingLabelValue);

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                // Nothing about WHAT to translate is sent: the message is the
                // id in the URL and the language is the reader's setting, so
                // nothing that could post here chooses what a model is told.
                headers: { "X-CSRF-Token": this.tokenValue },
                signal: this.#controller.signal,
            });

            if (run !== this.#run) return;

            if (false === response.ok || null === response.body) {
                this.#fail(await this.#kindOf(response));

                return;
            }

            await readFrames(response.body, (frame) => this.#apply(frame), () => run === this.#run);

            // Ended without `done` or `error`: the connection dropped. What
            // arrived stays readable; the server may still finish and store it.
            if (run === this.#run && null !== this.#controller) {
                this.#fail("connection_lost");
            }
        } catch (error) {
            if ("AbortError" !== error?.name && run === this.#run) {
                this.#fail("connection_lost");
            }
        }
    }

    #apply(frame) {
        if ("state" === frame.type) {
            this.#say("waiting" === frame.value ? this.waitingLabelValue : this.workingLabelValue);

            return;
        }

        if ("ping" === frame.type) {
            if ("" === this.#answer) {
                this.#say(this.readingLabelValue.replace("%elapsed%", this.#clock(frame.elapsed)));
            }

            return;
        }

        if ("token" === frame.type) {
            const first = "" === this.#answer;

            this.#answer += frame.text;
            this.translationTarget.textContent = this.#answer;

            // Shown as soon as there is any of it, with the way back to the
            // original; after that the reader's own choice stands.
            if (true === first) {
                this.pendingTarget.hidden = true;
                this.#say(this.workingLabelValue);
                this.#show("translation");
            }

            return;
        }

        if ("done" === frame.type) {
            this.#controller = null;

            if ("string" === typeof frame.text && "" !== frame.text.trim()) {
                this.#answer = frame.text;
                this.translationTarget.textContent = this.#answer;
            }

            this.fromLabelValue = "string" === typeof frame.fromLabel ? frame.fromLabel : "";
            this.pendingTarget.hidden = true;
            this.#say("");
            this.partialTarget.hidden = true !== frame.partial;
            this.quoteTarget.hidden = true !== frame.leavesOutQuote;
            this.#show(this.shownValue);

            return;
        }

        if ("error" === frame.type) {
            this.#fail(frame.kind);
        }
    }

    /** A run that ended without a translation: say why, and offer another. */
    #fail(kind) {
        this.#controller = null;
        this.pendingTarget.hidden = true;
        this.#say(this.errorLabelsValue[kind] ?? this.errorLabelsValue.generic);
        this.againTarget.hidden = false;

        // Whatever is in the translation box is what the bar describes: the
        // part that arrived, an earlier translation, or nothing.
        this.#show(this.shownValue);
    }

    async #kindOf(response) {
        try {
            const body = await response.json();

            return "string" === typeof body.error ? body.error : "generic";
        } catch {
            return "generic";
        }
    }

    #say(text) {
        this.statusTarget.textContent = text;
        this.statusTarget.hidden = "" === text;
    }

    /** Seconds as "45 s" or "2:05". */
    #clock(seconds) {
        const s = Math.max(0, Number(seconds) || 0);

        return 60 > s ? `${s} s` : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`;
    }
}
