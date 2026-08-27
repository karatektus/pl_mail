import { Controller } from "@hotwired/stimulus";
import { readFrames } from "../../ai/ndjson.js";

/**
 * "What is this conversation about?" — the reading pane's end of it.
 *
 * WHY THE SERVER MAKES THE CALL
 * ─────────────────────────────
 * `connect-src 'self'` is enforced, so this could not reach the model host even
 * if it wanted to — and should not want to: the host is an address on the
 * operator's private network. This posts to plMail and plMail asks.
 *
 * WHY fetch() AND NOT TURBO
 * ─────────────────────────
 * This is not a navigation. The response is a stream of tokens belonging to a
 * card inside a pane Turbo is not rendering, and a bare fetch() is the only way
 * to get an AbortController onto it. Aborting is not a nicety: it is the only
 * thing that stops a 20 GiB model on a one-GPU host when somebody opens another
 * thread — see #stopReading() and disconnect().
 *
 * WHY THERE IS NO ACCEPT AND NO DISCARD
 * ─────────────────────────────────────
 * This is the whole difference from the composer's panel. A draft is a
 * PROPOSAL — it goes into somebody's editor only if they say so, which is why
 * that controller has a preview, an insert and a throw-away. A summary is not
 * inserted anywhere. The server stores it as soon as it is finished and the
 * card shows it, so the only decisions left are "write one", "write another
 * one" and "stop".
 *
 * WHY textContent, NEVER innerHTML
 * ────────────────────────────────
 * This is model output derived from somebody's mail, arriving over a stream, in
 * a pane that also renders that mail. The one thing it must never be is markup.
 * The system prompt asks for plain text; this is what makes asking unnecessary.
 *
 * A STALE SUMMARY IS SHOWN, NOT HIDDEN
 * ────────────────────────────────────
 * The server renders the previous text greyed with a sentence saying the
 * conversation has moved on. A summary of a thread that has since gained one
 * "thanks" is still mostly true, and hiding it would make the half-minute
 * somebody already waited feel wasted. Pressing regenerate is what clears that
 * state, and it clears it by starting a run — see #run().
 */
export default class extends Controller {
    static targets = ["status", "pending", "output", "stale", "run", "stop"];

    static values = {
        url: String,

        /**
         * Its own per-action CSRF token, taken as a Stimulus value rather than
         * from the `csrf-token` meta tag.
         *
         * assets/csrf.js reads that tag, and the tag carries the shared `ajax`
         * token — so jsonCsrfHeaders() cannot mint a per-action one. This
         * follows the precedent csrf.js's own docblock names: the tenth caller,
         * settings/account_order_controller, "takes its token as a Stimulus
         * value from the template instead. That is left alone deliberately: it
         * is a per-action token." So does this, for the same reason — one token
         * good for every action makes any one XSS worth all of them, and this
         * action spends half a minute of a shared GPU.
         */
        token: String,

        /**
         * One label per state, because the states are the whole point.
         *
         * The wait here is longer than the composer's and the reason is
         * different: a cold call is about forty seconds before the first token,
         * of which eighteen are the model loading and twenty-three are the
         * conversation being read. A spinner across that is indistinguishable
         * from a dead button. Words are not — and these words say half a
         * minute rather than the composer's fifteen seconds, because that is
         * what was measured here.
         */
        sentLabel: { type: String, default: "Sent" },
        waitingLabel: { type: String, default: "The model is loading." },
        workingLabel: { type: String, default: "…" },
        stoppedLabel: { type: String, default: "Stopped" },

        failedLabel: { type: String, default: "No answer" },
        noAnswerLabel: { type: String, default: "No answer" },
        disabledLabel: { type: String, default: "No answer" },
        timeoutLabel: { type: String, default: "No answer" },
        unreachableLabel: { type: String, default: "No answer" },
        tooShortLabel: { type: String, default: "No answer" },
    };

    /** Aborts the fetch, which is what makes the server stop the model. */
    #controller = null;

    /**
     * Which run the reader belongs to.
     *
     * Pressing regenerate aborts the first, and an aborted fetch settles
     * asynchronously — so without this the OLD run's failure handler would
     * arrive after the new one had started and overwrite its status with a
     * sentence about a request nobody is waiting for any more.
     */
    #run = 0;

    /** What has arrived so far, so a stopped run still shows what it wrote. */
    #answer = "";

    #tokens = 0;

    /**
     * A pane that goes away mid-generation must not leave the model running.
     *
     * This is the cancellation, and it is not a nicety. mail_pane_controller
     * replaces the whole reading pane with `innerHTML = html` when another
     * conversation is opened, which destroys this controller — so opening a
     * second thread while a summary is being written has to stop the first, or
     * a 20 GiB model keeps generating a paragraph nobody will ever see while
     * the reader waits for the one they did ask for.
     *
     * Stimulus reconnects controllers under `innerHTML` injection; `mail--thread-read`
     * on this same fragment is the standing proof of that.
     */
    disconnect() {
        this.#stopReading();
    }

    /** Write one. The button under an unsummarised conversation. */
    run(event) {
        event?.preventDefault();

        this.#start();
    }

    /**
     * Write another one. The button beside a summary that is already there.
     *
     * The same request; the difference is entirely in what is on screen when it
     * starts. #start() clears the old text and the staleness notice, which is
     * what makes "this is out of date" stop being claimed the moment somebody
     * has acted on it.
     */
    regenerate(event) {
        event?.preventDefault();

        this.#start();
    }

    /**
     * Stop, and keep nothing.
     *
     * The abort reaches the server, whose next write fails, whose loop breaks,
     * which frees the generator, which drops the upstream response. Nothing is
     * stored for an abandoned run — half a summary sitting on the thread next
     * time it opens would read as a finished one — so the sentence says so.
     */
    stop(event) {
        event?.preventDefault();

        this.#stopReading();
        this.#pending(false);
        this.#stopping(false);
        this.#say(this.stoppedLabelValue);
    }

    async #start() {
        // Every run past the first is a REPLACEMENT, so the previous one's
        // fetch has to go before this one's counter moves — otherwise two
        // streams write into the same card.
        this.#stopReading();

        const run = ++this.#run;

        this.#answer = "";
        this.#tokens = 0;
        this.#output("");
        this.#stale(false);

        this.#controller = new AbortController();
        this.#stopping(true);

        // Said before the request is even built, so the very first thing after
        // the click is visible — and moving, because what follows can be forty
        // silent seconds.
        this.#pending(true);
        this.#say(this.sentLabelValue);

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                    "X-CSRF-Token": this.tokenValue,
                },
                // A form-encoded body with nothing in it. Everything the server
                // needs is the thread id already in the URL and the person
                // already in the session: what gets summarised is never taken
                // from the page, or anything that could post here would choose
                // what the model is told about somebody's mail.
                body: new URLSearchParams({}),
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
            // own request, and stop() has already said what it wants the card
            // to read. Anything else is a model host that is off, a proxy that
            // gave up, a network that went away.
            if ("AbortError" !== error?.name && run === this.#run) {
                this.#settle(this.failedLabelValue);
            }
        }
    }

    /**
     * Read the NDJSON frames as they land.
     *
     * The line framing is assets/ai/ndjson.js, shared with the composer. The
     * run guard is passed in rather than checked afterwards because an aborted
     * fetch settles asynchronously: without it the old run's frames keep
     * arriving into a card the new run has already claimed.
     */
    async #read(body, run) {
        await readFrames(body, (frame) => this.#apply(frame), () => run === this.#run);

        if (run !== this.#run) return;

        // The stream ended without a `done` or an `error` frame — the
        // connection dropped part-way. Whatever arrived is still on screen and
        // is still worth reading; what it is NOT is stored, because the server
        // only stores a run somebody stayed for. "Stopped" is the honest word
        // for both endings.
        if (null !== this.#controller) {
            this.#stopReading();
            this.#settle(this.stoppedLabelValue);
        }
    }

    /** One frame from the server, applied to the card. */
    #apply(frame) {
        if ("state" === frame.type) {
            // The one thing only the server can know: /api/ps lives on the
            // operator's private network. "waiting" means the model is not
            // resident and the next forty seconds produce nothing at all,
            // which is the difference between an honest wait and a dead button.
            this.#say("waiting" === frame.value ? this.waitingLabelValue : this.workingLabelValue);

            return;
        }

        if ("token" === frame.type) {
            if (0 === this.#tokens) {
                // The first token is the moment "waiting" stops being true —
                // and the moment the dots stop earning their place, because
                // text arriving is a better progress indicator than anything
                // drawn beside it.
                this.#pending(false);
                this.#say(this.workingLabelValue);
            }

            this.#tokens += 1;
            this.#answer += frame.text;
            this.#output(this.#answer);

            return;
        }

        if ("done" === frame.type) {
            this.#stopReading();

            // The server's tidied version REPLACES what was streamed, so what
            // is on screen is exactly what was just STORED — otherwise the card
            // would show one thing now and a slightly different one on the next
            // open, with nothing to explain the difference. tidy() strips a
            // code fence, which can only be recognised from both ends, so the
            // raw stream can end up two lines longer than the answer.
            if ("string" === typeof frame.text && "" !== frame.text.trim()) {
                this.#answer = frame.text;
                this.#output(this.#answer);
            }

            // No sentence at all. A finished summary explains itself, and a
            // status line reading "Summarised" over the summary is furniture.
            this.#settle("");

            return;
        }

        if ("error" === frame.type) {
            this.#stopReading();

            // Whatever arrived before it failed stays on screen. A host that
            // times out after two sentences has still written two sentences,
            // and clearing them to show a tidier error is the wrong trade for
            // somebody who has been waiting.
            this.#settle(this.#sentenceFor(frame.kind));
        }
    }

    /**
     * Everything that ends a run.
     *
     * One place, because "the stop button goes and the run button comes back"
     * has to happen for four endings — finished, stopped, failed, dropped — and
     * a card showing both Stop and Summarise again is a card claiming to be
     * doing two things at once.
     */
    #settle(sentence) {
        this.#pending(false);
        this.#stopping(false);
        this.#say(sentence);
    }

    /**
     * Abort the request, once.
     *
     * The abort is what propagates: it closes the connection, the server's next
     * write notices, its loop breaks, and the generator it frees drops the
     * response it was reading from Ollama. Nulling the controller FIRST makes
     * this safe to call from anywhere — disconnect(), stop(), a new run, done,
     * error — without a second abort landing on a settled request.
     */
    #stopReading() {
        const controller = this.#controller;

        this.#controller = null;
        controller?.abort();
    }

    // ── The surface ───────────────────────────────────────────────────────

    #say(message) {
        if (false === this.hasStatusTarget) return;

        this.statusTarget.textContent = message;
        // Empty means gone, not blank: an empty line above a summary is
        // furniture with nothing in it.
        this.statusTarget.hidden = "" === message;
    }

    /**
     * The dots, on or off.
     *
     * Deliberately not tied to #say(): the card says something in several
     * states including the finished ones, and dots under "Stopped." would be a
     * card claiming to still be working.
     */
    #pending(on) {
        if (false === this.hasPendingTarget) return;

        this.pendingTarget.hidden = false === on;
    }

    #output(text) {
        if (false === this.hasOutputTarget) return;

        // textContent, never innerHTML. See the class docblock.
        this.outputTarget.textContent = text;
        this.outputTarget.hidden = "" === text;

        // The greying belongs to the stored, stale copy. Text arriving now is
        // current by definition, so the class comes off at the first token
        // rather than at the end — a summary being written must not look like
        // one that is out of date.
        this.outputTarget.classList.remove("opacity-60");
    }

    /** The "this is out of date" notice, which only ever goes away. */
    #stale(on) {
        if (false === this.hasStaleTarget) return;

        this.staleTarget.hidden = false === on;
    }

    /** Stop replaces Summarise for the length of a run, and never sits beside it. */
    #stopping(on) {
        if (true === this.hasStopTarget) {
            this.stopTarget.hidden = false === on;
        }

        if (true === this.hasRunTarget) {
            this.runTarget.hidden = true === on;
        }
    }

    /**
     * A sentence for an error kind.
     *
     * The kinds are a closed set and the distinctions are real: a timeout is
     * worth simply trying again, an unreachable host is the operator's log, and
     * "the feature is off" is a setting only an administrator can change.
     * Collapsing them into one line is how a fixable configuration reads as a
     * broken feature.
     *
     * The kinds not listed — http_404, http_status, bad_response, unexpected —
     * fall through to the general sentence deliberately: nothing more specific
     * than "it could not be written" is actually known about them from in here,
     * and the composer's panel is where a missing model is worth naming because
     * that is where an administrator meets it first.
     */
    #sentenceFor(kind) {
        if ("disabled" === kind) return this.disabledLabelValue;
        if ("too_short" === kind) return this.tooShortLabelValue;
        if ("timeout" === kind) return this.timeoutLabelValue;
        if ("unreachable" === kind) return this.unreachableLabelValue;
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
