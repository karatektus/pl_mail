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
 * WHERE THIS IS ROOTED, AND WHY IT IS NOT THE CARD
 * ────────────────────────────────────────────────
 * On the thread's content wrapper, which is an ancestor of both halves of the
 * feature: the control at the right-hand end of the subject line, and the card
 * below the insight strip. It cannot be the card, because on a conversation
 * nobody has summarised THE CARD DOES NOT EXIST — an empty box headed "Summary"
 * is a box claiming a summary exists — and a controller cannot listen for the
 * click that creates its own element.
 *
 * So the card arrives instead: _thread_summary parks it in a <template>, which
 * a browser parses and then does not render, does not put in the accessibility
 * tree and does not return from querySelectorAll, and #mount() swaps the
 * template for its contents at the first click. A stored summary skips all of
 * that and is rendered as itself, at first paint, which is what storing it was
 * for.
 *
 * ONE CONTROL, IN ONE PLACE
 * ─────────────────────────
 * Summarise, Summarise again and Stop are the same control in the same place in
 * every state. A button that lived in the card as soon as the card existed
 * would be a button somebody has to find twice, and the second place would be
 * one that appears under their cursor half a minute after they clicked.
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
    static targets = [
        "status",
        "pending",
        "output",
        "stale", "partial", "full",
        "run",
        "runLabel",
        "stop",

        /**
         * The card, and the <template> holding a card that is not there yet.
         * Exactly one of the two is on the page at any moment: the template is
         * REPLACED by its own contents when the first run starts, so `card`
         * arriving is `cardTemplate` going.
         */
        "card",
        "cardTemplate",
    ];

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
        waitingFullLabel: { type: String, default: "The model is reading the whole conversation." },
        workingLabel: { type: String, default: "…" },
        stoppedLabel: { type: String, default: "Stopped" },

        /**
         * What the control says once it has written one. The offer changes
         * because the control does not move: "Summarise this conversation"
         * sitting over a finished summary offers to do something that has just
         * been done.
         */
        regenerateLabel: { type: String, default: "Summarise again" },

        failedLabel: { type: String, default: "No answer" },
        noAnswerLabel: { type: String, default: "No answer" },
        disabledLabel: { type: String, default: "No answer" },
        timeoutLabel: { type: String, default: "No answer" },
        modelMissingLabel: { type: String, default: "No answer" },
        refusedLabel: { type: String, default: "No answer" },
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
     * Whether the card has been put in by this controller.
     *
     * Not read off `hasCardTarget`, which is the same question asked of
     * Stimulus: it answers through a MutationObserver, so it is still false for
     * the rest of the task in which the card was inserted.
     */
    #mounted = false;

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

    /** Write one. The offer beside the subject of a conversation nobody has summarised. */
    run(event) {
        event?.preventDefault();

        this.#start();
    }

    /**
     * Write another one. The same offer, beside a summary that is already there.
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
     * Write one from the whole conversation, however long it is.
     *
     * Offered only beside the notice saying the last one was not — the reader
     * learns something was left out and the way to fix it is in the same
     * breath, rather than in a setting they would have to know exists.
     *
     * A SEPARATE ACTION AND NOT A CHECKBOX, because it is not a preference. It
     * costs a much longer wait and asks the model host to reserve a far larger
     * context window, which on a modest machine is slow and can fail to
     * allocate. That is a fair thing to spend once on a thread somebody cares
     * about and a poor default for every summary in the mailbox, so it is a
     * press with a warning on it and nothing is remembered.
     */
    full(event) {
        event?.preventDefault();

        this.#start(true);
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

    async #start(full = false) {
        // Every run past the first is a REPLACEMENT, so the previous one's
        // fetch has to go before this one's counter moves — otherwise two
        // streams write into the same card.
        this.#stopReading();

        const run = ++this.#run;

        this.#answer = "";
        this.#tokens = 0;

        this.#controller = new AbortController();

        // Before anything that can wait: the control is what was just clicked,
        // it is always on the page, and swapping it for Stop is the receipt.
        this.#stopping(true);

        if (true === this.#mount()) {
            await this.#adopted();

            // A card takes a task to put in, and a task is long enough to press
            // Stop in, or to ask for another one. Both take the controller away
            // — #stopReading() nulls it — and neither wants this run carrying on
            // into a fetch.
            if (run !== this.#run || null === this.#controller) return;
        }

        this.#output("");
        this.#stale(false);

        // Both go with the old summary they were about. The notice describes
        // how a paragraph that is no longer on screen was written, and the
        // offer is one the reader has just taken — leaving either up means the
        // card explains a summary it has already thrown away, and goes on
        // offering something that is already happening.
        //
        // Cleared HERE rather than only in #full(), because it is true of every
        // run: an ordinary regenerate replaces the same paragraph, and the
        // notice attached to it is no more current than the text was. `done`
        // sets both again from what this run actually sent, so a regenerate
        // that is still partial says so the moment it has an answer to say it
        // about.
        this.#partial(false);
        this.#offerFull(false);

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
                // ONE field, and it is not "what to summarise". Everything the
                // server needs to decide THAT is the thread id already in the
                // URL and the person already in the session: what gets
                // summarised is never taken from the page, or anything that
                // could post here would choose what the model is told about
                // somebody's mail. How much of their own thread to send is a
                // different question, and it is the one the card asks.
                body: new URLSearchParams(true === full ? { full: "1" } : {}),
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
            // Three states, not two. "waiting_full" is its own sentence
            // because the wait it describes is minutes rather than the
            // "about a minute" the cold-load line promises — a progress
            // message that under-promises the wait by an order of magnitude is
            // how somebody decides the button is broken and reloads the page
            // half way through the thing they asked for.
            if ("waiting_full" === frame.value) {
                this.#say(this.waitingFullLabelValue);
            } else {
                this.#say("waiting" === frame.value ? this.waitingLabelValue : this.workingLabelValue);
            }

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

            // Whether the model was shown the whole conversation. Sent with
            // the answer rather than assumed from the previous render: the
            // thread may have grown since the page was drawn, and this run's
            // transcript is the only one that describes what was just read.
            this.#partial(true === frame.partial);

            // The offer is only worth making while it would change something.
            // A run that already sent everything has nothing more to send, and
            // a thread past the ceiling is still partial after a full run — so
            // the button goes either way, and the notice standing alone says
            // the honest thing: this is as much as it can see.
            this.#offerFull(true === frame.partial && true !== frame.full);

            // No sentence at all. A finished summary explains itself, and a
            // status line reading "Summarised" over the summary is furniture.
            this.#settle("");

            // Only on `done`. A run that was stopped or that failed wrote
            // nothing, so the offer standing over it is still "summarise this",
            // and relabelling it would claim work that is not there.
            this.#offerAgain();

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

    /**
     * Put the card in, once, in the place the markup kept for it.
     *
     * The <template> is REPLACED by its own contents, so the card lands exactly
     * where _thread_summary said it goes — under the insight strip — without
     * this controller knowing anything about the page around it, and without a
     * second copy of that markup living in here. The template is consumed by
     * the swap, which is most of what makes this idempotent; #mounted covers
     * the rest, because Stimulus has not noticed either the arrival or the
     * departure yet when this returns.
     *
     * Returns whether anything was inserted, because the caller pays a task for
     * it (see #adopted()) and must not pay it for a card that was already on
     * the page — the stored-summary case, which is most opens of a summarised
     * conversation.
     */
    #mount() {
        if (true === this.#mounted || true === this.hasCardTarget) return false;
        if (false === this.hasCardTemplateTarget) return false;

        this.#mounted = true;

        const template = this.cardTemplateTarget;

        template.replaceWith(template.content.cloneNode(true));

        return true;
    }

    /**
     * One task, after the card has been put in. Two reasons, both about that
     * same instant.
     *
     * Stimulus finds targets through a MutationObserver, so the status line and
     * the dots inside markup inserted a moment ago are not targets yet — they
     * become targets at the end of this task, and everything written to them
     * before that is written to nothing at all.
     *
     * And an aria-live region announces CHANGES. One that is inserted with its
     * sentence already inside it is a region the screen reader was not watching
     * when the sentence arrived, so the first and most important state — "sent,
     * this will take about a minute" — would be the one nobody hears.
     *
     * A task, not a frame: rAF does not run in a background tab, and a summary
     * is the one thing here somebody deliberately starts and then switches away
     * from.
     */
    #adopted() {
        return new Promise((resolve) => {
            setTimeout(resolve, 0);
        });
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

    /**
     * The summary as a list, rebuilt from the whole accumulated text on every
     * token.
     *
     * REBUILT, not appended to. A token can land anywhere — mid-word, mid-line,
     * or as the newline that starts the next point — so there is no such thing
     * as "the current item" to push a character onto. Re-splitting three short
     * lines a few hundred times costs nothing measurable and removes the entire
     * class of bug where a line break arrives and the item it should have ended
     * keeps growing.
     *
     * Rendered as a list WHILE it streams rather than as prose that becomes a
     * list at the end: the reader is already reading it by then, and text that
     * rearranges itself under them is worse than either shape on its own.
     *
     * Still textContent and never innerHTML — see the class docblock. The
     * elements are built here and only their text comes from the model, so the
     * rule holds exactly as it did when this was one paragraph.
     *
     * THE SAME SPLIT LIVES IN _thread_summary.html.twig, which renders a stored
     * summary server-side. Two implementations of one rule; change one, change
     * the other.
     */
    #output(text) {
        if (false === this.hasOutputTarget) return;

        this.outputTarget.replaceChildren();

        for (const raw of String(text).split("\n")) {
            const line = this.#unmarked(raw.trim());

            if ("" === line) {
                continue;
            }

            const item = document.createElement("li");

            // textContent, never innerHTML. See the class docblock.
            item.textContent = line;
            this.outputTarget.appendChild(item);
        }

        this.outputTarget.hidden = "" === text;

        // The greying belongs to the stored, stale copy. Text arriving now is
        // current by definition, so the class comes off at the first token
        // rather than at the end — a summary being written must not look like
        // one that is out of date.
        this.outputTarget.classList.remove("opacity-60");
    }

    /**
     * A leading bullet the model wrote anyway, taken off.
     *
     * The prompt asks for none, because the card draws its own — but a summary
     * stored before it did asks for one, and a model obliges unasked often
     * enough that stripping is cheaper than trusting. Leading only: the dash in
     * "Backend / Python – Cara Care" is part of the sentence.
     */
    #unmarked(line) {
        for (const marker of ["– ", "— ", "- ", "* ", "• "]) {
            if (line.startsWith(marker)) {
                return line.slice(marker.length).trim();
            }
        }

        return line;
    }

    /** The "this is out of date" notice, which only ever goes away. */
    #stale(on) {
        if (false === this.hasStaleTarget) return;

        this.staleTarget.hidden = false === on;
    }

    /**
     * The "it did not see all of this" notice.
     *
     * Set BOTH WAYS from the run's own answer, unlike #stale which only ever
     * clears. Staleness is a fact about the past — the thread moved on after
     * the summary was written — so starting a run always ends it. Partialness
     * is a fact about the conversation as it stands: a thread too long to fit
     * is still too long after regenerating, and a notice that vanished on a
     * click would tell the reader the second summary saw everything when it saw
     * exactly as little as the first.
     */
    #partial(on) {
        if (false === this.hasPartialTarget) return;

        this.partialTarget.hidden = false === on;
    }

    /** The "send the whole thing" offer, which only stands while it would help. */
    #offerFull(on) {
        if (false === this.hasFullTarget) return;

        this.fullTarget.hidden = false === on;
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
     * The offer, once there is something to make it again about.
     *
     * The label is the button's accessible name — there is no aria-label beside
     * it — so rewriting the text is the whole change, and a screen reader
     * reaching the control next reads the offer that is actually on it.
     */
    #offerAgain() {
        if (false === this.hasRunLabelTarget) return;

        this.runLabelTarget.textContent = this.regenerateLabelValue;
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
     * http_404 IS NAMED NOW, and the reasoning that kept it quiet was wrong.
     * This used to send it to the general sentence on the grounds that "the
     * composer's panel is where a missing model is worth naming because that is
     * where an administrator meets it first". Two things are wrong with that.
     * The reader of a thread is not always an administrator and cannot be sent
     * to a panel they may not be able to open; and the summary card is where
     * this failure is actually met, every time a thread is opened, for as long
     * as the model stays unpulled. Search has never hidden it — "the model host
     * does not have the search model" — and this is the same sentence about the
     * other model.
     *
     * The kinds still not listed — http_status, unexpected — do fall through
     * deliberately: nothing more specific than "it could not be written" is
     * known about them from in here. What changed is that they are now written
     * to the server log with the host's own words, so "could not be written"
     * has somewhere to lead.
     */
    #sentenceFor(kind) {
        if ("disabled" === kind) return this.disabledLabelValue;
        if ("too_short" === kind) return this.tooShortLabelValue;
        if ("timeout" === kind) return this.timeoutLabelValue;
        if ("unreachable" === kind) return this.unreachableLabelValue;
        if ("http_404" === kind) return this.modelMissingLabelValue;

        // SPLIT OUT OF THE GENERAL SENTENCE. "Refused" and "we do not know" are
        // different errands: a refusal means the host answered and said no —
        // most often because it could not give the request what it asked for,
        // which is what a context window too large for the machine looks like
        // from here — and that is a thing an administrator can act on. Leaving
        // it in the general bucket meant the one failure with a known shape
        // read identically to the ones with none.
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
