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
 * WHY fetch() AND NOT TURBO
 * ─────────────────────────
 * This is not a navigation and must not be treated as one. Turbo would want to
 * own the response, and the response is a stream of tokens that belongs to a
 * surface inside a window Turbo is not rendering. A bare fetch() is also the
 * only way to get an AbortController onto it, and aborting is not a nicety
 * here — see #stopReading().
 *
 * WHY THE TOKENS DO NOT GO STRAIGHT INTO THE EDITOR
 * ─────────────────────────────────────────────────
 * Two reasons, and the first one is the one users meet.
 *
 * Undo. The composer's editor is a contenteditable, and the browser's undo
 * stack records what happens to it. Inserting a token at a time makes hundreds
 * of entries, so Ctrl+Z — the first thing anybody tries when a machine has put
 * something in their email — removes one token, then another, and then starts
 * eating what the person wrote themselves. Accepting the whole answer as ONE
 * execCommand is one entry, which undoes in one keystroke.
 *
 * Position. The writer can carry on typing while this runs, and the composer's
 * other insertions all hang off a selection range saved on blur, which is the
 * fragile piece in every one of them. A surface that is not the document has no
 * caret to lose.
 *
 * So tokens accumulate in a preview, plainly provisional, and go in as one
 * operation when somebody says so.
 *
 * WHY IT STILL APPENDS AT THE END RATHER THAN AT THE CARET
 * ────────────────────────────────────────────────────────
 * Unchanged from before this was streamed, and for the original reason: opening
 * the menu moved focus, so "at the caret" is a position the writer did not
 * choose. The end of the body is somewhere they can see, and it is where a
 * draft grows anyway.
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

        /**
         * One label per state, because the states are the whole point.
         *
         * The reported bug was "I click the AI button and nothing happens", and
         * the deeper half of it survives any fix to the selector: one
         * integrated GPU holds a 20 GiB model, and outside the five-minute
         * keep-alive window the first request produces NOTHING for about
         * thirteen seconds while it loads off disk. A spinner during that is
         * indistinguishable from a dead button. Words are not.
         */
        sentLabel: { type: String, default: "Sent" },
        waitingLabel: { type: String, default: "The model is loading." },
        busyLabel: { type: String, default: "…" },
        doneLabel: { type: String, default: "Finished" },
        stoppedLabel: { type: String, default: "Stopped" },

        /** `%count%` and `%rate%` are filled in here; Twig only runs once. */
        rateLabel: { type: String, default: "%count% tokens · %rate%/s" },
        /** The same numbers plus the load cost, for the status line's tooltip. */
        timingLabel: { type: String, default: "" },

        failedLabel: { type: String, default: "No answer" },
        noAnswerLabel: { type: String, default: "No answer" },
        disabledLabel: { type: String, default: "No answer" },
        timeoutLabel: { type: String, default: "No answer" },
        notPulledLabel: { type: String, default: "No answer" },
        refusedLabel: { type: String, default: "No answer" },
    };

    static targets = ["panel", "status", "meter", "preview", "stop", "actions"];

    /**
     * How often the token counter may be redrawn, in milliseconds.
     *
     * The model emits around nineteen tokens a second, and rewriting a number
     * nineteen times a second is layout work for a readout nobody can read at
     * that speed. Five times a second still looks continuous.
     */
    static METER_INTERVAL = 200;

    /** Aborts the fetch, which is what makes the server stop the model. */
    #controller = null;

    /**
     * Which run the reader belongs to.
     *
     * Picking a second task aborts the first, and an aborted fetch settles
     * asynchronously — so without this the OLD run's failure handler would
     * arrive after the new run had started and overwrite its status with a
     * sentence about a request nobody is waiting for any more.
     */
    #run = 0;

    /** The task of the run on screen, so "try again" knows what to repeat. */
    #task = null;

    /** What #accept() would put in the draft. */
    #answer = "";

    #tokens = 0;
    #firstTokenAt = 0;
    #meterDrawnAt = 0;

    /**
     * A composer that goes away mid-generation must not leave the model running.
     *
     * disconnect() covers all three ways out: the window closed, the draft
     * discarded, or Turbo navigating the page out from under it. Every one of
     * them removes this element, and every one of them used to leave a 20 GiB
     * model generating a reply for nobody — on a single-GPU machine, with every
     * other feature queued behind it.
     */
    disconnect() {
        this.#stopReading();
    }

    /**
     * @param {PointerEvent & {params: {task: string}}} event
     */
    run(event) {
        event.preventDefault();

        const task = event.params?.task;
        if (!task) return;

        this.#task = task;
        this.#start();
    }

    /** "Try again" — the same task, from the draft as it stands now. */
    regenerate(event) {
        event?.preventDefault();

        if (null === this.#task) return;

        this.#start();
    }

    /**
     * Stop generating, and keep what arrived.
     *
     * Aborting the fetch closes the connection; the server notices on its next
     * write, breaks its own loop and drops the upstream response, and Ollama
     * stops generating for a client that has gone away. Nothing here waits for
     * any of that — the panel moves to its stopped state immediately, because
     * the user has already decided.
     */
    stop(event) {
        event?.preventDefault();

        if (null === this.#controller) return;

        this.#stopReading();
        this.#settle(this.stoppedLabelValue);
    }

    /**
     * Put the answer in the draft, as ONE operation.
     *
     * execCommand rather than the appendChild this used to do, and the reason
     * is the undo stack: a scripted DOM mutation is not recorded in it at all,
     * so Ctrl+Z after inserting used to undo whatever the writer had typed
     * BEFORE — silently deleting their own words to remove text they had just
     * asked for. execCommand('insertText') is recorded, once, and takes the
     * whole block back out in one keystroke.
     *
     * insertText and not insertHTML: everything the model produces is plain
     * text by construction (see WritingAssistant), and building markup out of
     * it would mean escaping model output into HTML for no gain whatsoever.
     */
    accept(event) {
        event?.preventDefault();

        const text = this.#answer;
        if ("" === text.trim()) return;

        const target = this.#writingSurface();
        if (null === target) return;

        target.focus();
        this.#caretToEnd(target);

        // A blank line between what was there and what arrives, unless the
        // draft is empty — a reply that opens with two blank lines is a thing
        // the writer then has to delete.
        const separator = "" === this.#textOf(target).trim() ? "" : "\n\n";

        if (false === document.execCommand("insertText", false, separator + text)) {
            // A browser that refuses execCommand still has to end up with the
            // text somewhere. One undo step is lost; the draft is not.
            this.#appendPlainly(target, separator + text);
        }

        // execCommand raises a native `input` event of its own, but the
        // fallback above raises none — and `input` is what wakes the autosave
        // and mirrors the body into the hidden field. Raised unconditionally
        // because the autosave is debounced, so a second one costs nothing and
        // a missing one loses the text on reload.
        target.dispatchEvent(new Event("input", { bubbles: true }));

        target.scrollIntoView({ block: "nearest" });

        this.#reset();
    }

    /** Throw the suggestion away. The draft is untouched, because it always was. */
    discard(event) {
        event?.preventDefault();

        this.#stopReading();
        this.#reset();
    }

    // ── The run ───────────────────────────────────────────────────────────

    async #start() {
        // A second task while one is running means the writer changed their
        // mind, and the honest answer to that is to stop the first — not to
        // ignore the click, which is the shape of bug this whole feature was
        // reported as.
        this.#stopReading();

        this.#answer = "";
        this.#tokens = 0;
        this.#firstTokenAt = 0;
        this.#meterDrawnAt = 0;

        this.#open();
        this.#preview("");
        this.#meter("");
        this.#actions(false);
        this.#stopping(false);

        const editor = this.#writingSurface();

        if (null === editor) {
            // Said out loud rather than returned quietly on. A null editor is
            // how the previous version of this failed — two target selectors
            // that matched nothing, so run() returned at its first guard and
            // the feature did nothing at all, for months.
            this.#say(this.failedLabelValue);

            return;
        }

        const run = ++this.#run;

        this.#controller = new AbortController();
        this.#stopping(true);

        // Said before the request is even built, so the very first thing that
        // happens after the click is visible.
        this.#say(this.sentLabelValue);

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: jsonCsrfHeaders({ "Content-Type": "application/x-www-form-urlencoded" }),
                body: new URLSearchParams({
                    task: this.#task,
                    draft: this.#textOf(editor).trim(),
                    subject: this.#subject(),
                    inReplyTo: this.inReplyToValue,
                }),
                signal: this.#controller.signal,
            });

            if (run !== this.#run) return;

            if (false === response.ok || null === response.body) {
                this.#settle(await this.#reasonFor(response));

                return;
            }

            await this.#read(response.body, run);
        } catch (error) {
            // An abort is not a failure — it is this controller stopping its
            // own request, and stop()/discard() have already said what they
            // want the panel to read. Anything else is a model host that is
            // off, a proxy that gave up, a network that went away: none of it
            // worth a stack trace at somebody trying to write an email.
            if ("AbortError" !== error?.name && run === this.#run) {
                this.#settle(this.failedLabelValue);
            }
        }
    }

    /**
     * Read the NDJSON frames as they land.
     *
     * A chunk off the wire is NOT a frame: it can carry half an object, or
     * three of them, and the tail is held back until its newline arrives. The
     * server does exactly this to Ollama's own stream one hop upstream — the
     * failure mode for splitting on chunk boundaries instead is a parser that
     * works perfectly against localhost and silently drops words over a real
     * network.
     */
    async #read(body, run) {
        const reader = body.getReader();
        const decoder = new TextDecoder();
        let buffer = "";

        for (;;) {
            const { value, done } = await reader.read();

            if (run !== this.#run) return;

            if (true === done) break;

            // `stream: true` so a multi-byte character split across two chunks
            // is held rather than decoded into a replacement character. Every
            // umlaut in a German draft sits on that boundary eventually.
            buffer += decoder.decode(value, { stream: true });

            let cut;

            while (-1 !== (cut = buffer.indexOf("\n"))) {
                const line = buffer.slice(0, cut).trim();
                buffer = buffer.slice(cut + 1);

                if ("" === line) continue;

                let frame;

                try {
                    frame = JSON.parse(line);
                } catch {
                    // A truncated final line from a connection that died
                    // mid-frame. Skipped rather than fatal: the frames before
                    // it are still the writer's text.
                    continue;
                }

                this.#apply(frame);
            }
        }

        // The stream ended without a `done` or an `error` frame — the
        // connection dropped part-way. Whatever arrived is still worth
        // offering, which is what stopped means.
        if (null !== this.#controller) {
            this.#stopReading();
            this.#settle(this.stoppedLabelValue);
        }
    }

    /** One frame from the server, applied to the surface. */
    #apply(frame) {
        if ("state" === frame.type) {
            // The one thing only the server can know: /api/ps lives on the
            // operator's private network. "waiting" means the model is not
            // resident and the next thirteen seconds produce nothing at all,
            // which is the difference between an honest wait and a dead button.
            this.#say("waiting" === frame.value ? this.waitingLabelValue : this.busyLabelValue);

            return;
        }

        if ("token" === frame.type) {
            if (0 === this.#tokens) {
                // The first token is the moment "waiting" stops being true.
                this.#firstTokenAt = performance.now();
                this.#say(this.busyLabelValue);
            }

            this.#tokens += 1;
            this.#answer += frame.text;
            this.#preview(this.#answer);
            this.#drawMeter(false);

            return;
        }

        if ("done" === frame.type) {
            this.#stopReading();

            // The server's tidied version REPLACES what was streamed, so that
            // what a person reads is exactly what #accept() will insert. tidy()
            // strips a code fence, which can only be recognised from both ends,
            // so the raw stream can end up one line longer than the answer.
            if ("string" === typeof frame.text && "" !== frame.text.trim()) {
                this.#answer = frame.text;
                this.#preview(this.#answer);
            }

            this.#drawMeter(true);
            this.#settle(this.doneLabelValue, this.#timingTitle(frame));

            return;
        }

        if ("error" === frame.type) {
            this.#stopReading();

            // Whatever arrived before it failed is kept, and #settle() offers
            // to insert it if there is any. A host that times out after two
            // paragraphs has still written two paragraphs, and throwing them
            // away to show a tidier error message is the wrong trade for
            // somebody who has been waiting.
            this.#settle(this.#sentenceFor(frame.kind));
        }
    }

    /**
     * Everything that ends a run and leaves something to decide about.
     *
     * One place, because "the stop button goes, the accept row arrives" has to
     * happen for four different endings — finished, stopped, aborted, dropped —
     * and a panel that ends up with both a Stop and an Insert is a panel
     * claiming to be doing two things at once.
     */
    #settle(sentence, title = "") {
        this.#stopping(false);
        this.#say(sentence, title);
        this.#actions("" !== this.#answer.trim());
    }

    /**
     * Back to an idle composer, carrying no furniture.
     *
     * The task is deliberately kept: "try again" after an insert should repeat
     * what was asked for, and the menu is the only other place that says what
     * that was.
     */
    #reset() {
        this.#answer = "";
        this.#say("");
        this.#preview("");
        this.#meter("");
        this.#actions(false);
        this.#stopping(false);

        if (true === this.hasPanelTarget) {
            this.panelTarget.hidden = true;
        }
    }

    /**
     * Abort the request, once.
     *
     * The abort is what propagates: it closes the connection, the server's next
     * write notices, and its own loop breaks and drops the response it is
     * reading from Ollama. Nulling the controller first makes this safe to call
     * from anywhere — disconnect(), stop(), discard(), a new run — without a
     * second abort landing on a settled request.
     */
    #stopReading() {
        const controller = this.#controller;

        this.#controller = null;
        controller?.abort();
    }

    // ── The surface ───────────────────────────────────────────────────────

    #open() {
        if (true === this.hasPanelTarget) {
            this.panelTarget.hidden = false;
        }
    }

    /**
     * Empty means gone, not blank: an empty line floating over the action bar
     * is furniture with nothing in it.
     */
    #say(message, title = "") {
        if (false === this.hasStatusTarget) {
            return;
        }

        this.statusTarget.textContent = message;
        this.statusTarget.hidden = "" === message;

        // The numbers, where they can be read on purpose. They are in the
        // aria-hidden meter for the rest of the time precisely because they
        // change too fast to be announced.
        if ("" === title) {
            this.statusTarget.removeAttribute("title");
        } else {
            this.statusTarget.title = title;
        }
    }

    #preview(text) {
        if (false === this.hasPreviewTarget) {
            return;
        }

        // textContent, never innerHTML. This is model output on its way to
        // somebody's email, and the one thing it must never be is markup.
        this.previewTarget.textContent = text;
        this.previewTarget.hidden = "" === text;

        // Instant, not smooth: this runs about nineteen times a second, and a
        // smooth scroll that is restarted every 50ms never arrives anywhere.
        this.previewTarget.scrollTop = this.previewTarget.scrollHeight;
    }

    #meter(text) {
        if (false === this.hasMeterTarget) {
            return;
        }

        this.meterTarget.textContent = text;
        this.meterTarget.hidden = "" === text;
    }

    /**
     * The live throughput readout.
     *
     * Measured from the FIRST TOKEN and not from the click, deliberately. A
     * cold model spends thirteen seconds loading before it emits anything, and
     * counting that in would report a machine that generates at nineteen tokens
     * a second as managing four — a number that is wrong about the hardware and
     * gets worse the more honest the wait was.
     */
    #drawMeter(force) {
        // Nothing arrived, so there is no rate — and dividing by a start time
        // that was never set would print a very confident wrong number.
        if (0 === this.#tokens) {
            return;
        }

        const now = performance.now();

        if (false === force && now - this.#meterDrawnAt < this.constructor.METER_INTERVAL) {
            return;
        }

        this.#meterDrawnAt = now;

        const seconds = (now - this.#firstTokenAt) / 1000;
        const rate = seconds > 0 ? this.#tokens / seconds : 0;

        this.#meter(
            this.rateLabelValue
                .replace("%count%", String(this.#tokens))
                .replace("%rate%", rate.toFixed(1)),
        );
    }

    /**
     * What the whole thing cost, for the status line's tooltip.
     *
     * Ollama's own numbers rather than the browser's, because they separate the
     * load from the generation and the browser cannot: from here a cold run and
     * a slow one look identical, and they are the two things an operator most
     * needs to tell apart.
     */
    #timingTitle(frame) {
        if ("" === this.timingLabelValue) {
            return "";
        }

        return this.timingLabelValue
            .replace("%count%", String(frame.evalTokens ?? this.#tokens))
            .replace("%seconds%", this.#seconds(frame.evalMs))
            .replace("%load%", this.#seconds(frame.loadMs))
            .replace("%total%", this.#seconds(frame.totalMs));
    }

    /** Milliseconds as seconds, and "—" for what the host did not measure. */
    #seconds(milliseconds) {
        return "number" === typeof milliseconds ? (milliseconds / 1000).toFixed(1) : "—";
    }

    #stopping(on) {
        if (true === this.hasStopTarget) {
            this.stopTarget.hidden = false === on;
        }
    }

    #actions(on) {
        if (true === this.hasActionsTarget) {
            this.actionsTarget.hidden = false === on;
        }
    }

    // ── The draft ─────────────────────────────────────────────────────────

    /**
     * Where the writer's words actually are.
     *
     * TWO SURFACES, NOT ONE. The composer has a plain-text mode that swaps the
     * contenteditable for a textarea, and in that mode the editor is hidden and
     * holds stale HTML. This used to read and write the editor unconditionally,
     * so in plain text it sent the model whatever was in the draft before the
     * switch and then inserted the answer into an element nobody could see —
     * the same silent nothing that link, emoji and signature were greyed out to
     * avoid. Writing help is if anything MORE useful in plain text, since plain
     * text is all it produces, so it works there rather than being disabled.
     */
    #writingSurface() {
        const composer = this.element.closest("[data-controller~='compose--compose']");

        if (null === composer) return null;

        // `disabled`, not `hidden`: compose--compose#_setPlainText() disables
        // the textarea in rich mode and enables it in plain, and that flag is
        // also what decides which body actually gets submitted.
        const plain = composer.querySelector("[data-compose--compose-target='plainBody']");

        if (null !== plain && false === plain.disabled) {
            return plain;
        }

        // The identifier is `compose--compose-toolbar`, so the target attribute
        // is `data-compose--compose-toolbar-target`. This asked for
        // `data-compose--toolbar-target`, which the composer has never
        // rendered: it matched nothing, run() returned at its first guard, and
        // the whole feature did nothing at all — no request, no status, no
        // error. That is the "I click the AI button and nothing happens" report
        // in full.
        return composer.querySelector("[data-compose--compose-toolbar-target='editor']");
    }

    /**
     * Same mistake, quieter symptom: there is no `input[name="subject"]` in the
     * composer either, so every request went out with an empty subject and the
     * model was answering with one hand behind its back. Nothing failed, the
     * replies were merely worse than they should have been.
     */
    #subject() {
        return this.element.closest("[data-controller~='compose--compose']")
            ?.querySelector("[data-compose--compose-target='subject']")?.value ?? "";
    }

    /** What is written, from either surface, without the markup around it. */
    #textOf(surface) {
        if (undefined !== surface.value) {
            return surface.value;
        }

        return surface.innerText ?? surface.textContent ?? "";
    }

    /** Put the caret at the very end, which is where the answer goes. */
    #caretToEnd(surface) {
        if (undefined !== surface.value) {
            surface.selectionStart = surface.value.length;
            surface.selectionEnd = surface.value.length;

            return;
        }

        const range = document.createRange();
        range.selectNodeContents(surface);
        range.collapse(false);

        const selection = document.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(range);
    }

    /** The last resort, for a browser that refused execCommand. */
    #appendPlainly(surface, text) {
        if (undefined !== surface.value) {
            surface.value += text;

            return;
        }

        const block = document.createElement("div");

        for (const [index, line] of text.split(/\r?\n/).entries()) {
            if (index > 0) block.appendChild(document.createElement("br"));
            block.appendChild(document.createTextNode(line));
        }

        surface.appendChild(block);
    }

    /**
     * Which sentence a refusal deserves.
     *
     * A category, never a stack trace and never a silent no-op. The server
     * distinguishes six of them and they are genuinely different problems: a
     * model that was never pulled is one command on the host, a host that
     * refuses is its log, a timeout is worth simply trying again, and "the
     * feature is off" is a setting only an administrator can change. Collapsing
     * them into one line is how a fixable configuration reads as a broken
     * feature.
     *
     * The three not listed here — unreachable, bad_response, unexpected —
     * deliberately fall through to the two general sentences, because nothing
     * more specific than "it could not be sent" or "it gave no answer" is
     * actually known about them from in here.
     */
    #sentenceFor(kind) {
        if ("disabled" === kind) return this.disabledLabelValue;
        if ("timeout" === kind) return this.timeoutLabelValue;
        if ("http_404" === kind) return this.notPulledLabelValue;
        if ("http_status" === kind) return this.refusedLabelValue;
        if ("no_answer" === kind || "bad_response" === kind) return this.noAnswerLabelValue;

        return this.failedLabelValue;
    }

    /** The same, for a refusal that arrived as an HTTP status rather than a frame. */
    async #reasonFor(response) {
        let error = "";

        try {
            ({ error } = await response.json());
        } catch {
            // Not JSON at all — a proxy's own error page, say. The generic
            // sentence is the honest one for that.
        }

        return this.#sentenceFor(error);
    }
}
