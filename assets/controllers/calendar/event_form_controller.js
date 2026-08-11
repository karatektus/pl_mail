import { Controller } from "@hotwired/stimulus"

/**
 * The event editor's own validation, and the one place that decides what
 * "changing the start" does to the end.
 *
 * It exists because the browser's built-in validation is not usable here. A
 * `required` field that fails opens a native bubble: text this application did
 * not write, in whatever language the browser is set to rather than the one the
 * page is in, attached to no element, announced inconsistently, and gone on the
 * next keystroke. WCAG 3.3.1 wants the error identified *in text* and tied to
 * the field it is about, which a bubble is not. So the form carries `novalidate`
 * and this renders the same refusals as real markup — visible text, aria-invalid
 * on the control, and the message reachable through aria-describedby.
 *
 * **It is not the authority.** Everything checked here is checked again in
 * CalendarController::eventSave(), which answers 422 and re-renders this same
 * editor with the same sentences in it (see _event_modal.stream.html.twig). This
 * half only makes the answer instant; deleting it would cost a round trip and
 * nothing else, which is the property that makes it safe to have at all.
 *
 * The messages come from the markup — `data-error-message` on the field, put
 * there by Twig — rather than from string literals here. There is one
 * translation catalogue and it is not this file: an untranslated sentence in a
 * controller is how the German build ends up with an English error in it.
 */
export default class extends Controller {
    static targets = [
        "title", "titleError",
        "starts", "startsError",
        "ends", "endsError",
        "summary", "summaryList",
    ]

    connect() {
        // What the start said a moment ago, so a change to it can be read as a
        // delta rather than as an absolute. See shiftEnd().
        this.previousStart = this.hasStartsTarget ? this.startsTarget.value : ""

        // Typing into a field that is complaining should stop it complaining.
        // Bound on the form rather than per field: the fields are replaced
        // wholesale whenever the editor is re-rendered, and a listener per
        // input would have to be re-bound each time.
        this.onInput = (event) => this.clearFieldError(event.target)
        this.element.addEventListener("input", this.onInput)

        // A refusal that came back from the SERVER arrives as fresh markup with
        // the fields already marked, and nothing has moved the keyboard to
        // them: the submit that produced it left focus on the Save button, and
        // `autofocus` does not fire again in a document that is already loaded.
        // So the same thing validate() does for a client-side refusal is done
        // here for a server-side one, and the two paths end in the same place.
        //
        // A no-op on an ordinary open, where nothing is marked.
        this.element.querySelector("[aria-invalid='true']")?.focus()
    }

    disconnect() {
        this.element.removeEventListener("input", this.onInput)
    }

    /**
     * Refuse the submit if anything is wrong, and say what.
     *
     * Delete is exempt, and has to be: it submits this same form through
     * `formaction`, and it does not care that the title is blank — the user is
     * throwing the event away. `formNoValidate` on the submitter is the same
     * flag the browser would have honoured, read here because `novalidate` on
     * the form means the browser is no longer reading it.
     */
    validate(event) {
        if (event.submitter?.formNoValidate) {
            return
        }

        const problems = []

        // ── Title ──
        if (this.hasTitleTarget && "" === this.titleTarget.value.trim()) {
            problems.push(this.markInvalid(
                this.titleTarget,
                this.hasTitleErrorTarget ? this.titleErrorTarget : null,
            ))
        }

        // ── End before start ──
        //
        // Deliberately NOT auto-corrected. Moving the end to rescue an explicit
        // 18:00 → 08:00 would silently save a different meeting from the one on
        // screen, and the user typed both of those numbers on purpose. The end
        // moves by itself only when the START moves — see shiftEnd() — which is
        // the half of the behaviour people actually rely on.
        //
        // Compared as strings, which is safe and is why the fields are read
        // rather than parsed: `datetime-local` values are ISO-ordered
        // (YYYY-MM-DDTHH:MM), so a lexical comparison is a chronological one,
        // and no timezone is introduced that the server would then read back
        // differently. `<` and not `<=`, to agree exactly with the server's
        // `$endsAt < $startsAt`; a zero-length event is odd but it is not this
        // check's business to refuse it.
        if (this.hasStartsTarget && this.hasEndsTarget) {
            const start = this.startsTarget.value
            const end = this.endsTarget.value

            if ("" !== start && "" !== end && end < start) {
                problems.push(this.markInvalid(
                    this.endsTarget,
                    this.hasEndsErrorTarget ? this.endsErrorTarget : null,
                ))
            }
        }

        if (0 === problems.length) {
            this.clearSummary()

            return
        }

        event.preventDefault()

        this.showSummary(problems)

        // Focus follows the first thing that is wrong, so the keyboard lands
        // where the work is. The summary announces on its own — it is a live
        // region — so this does not have to steal the announcement to be heard.
        const first = this.element.querySelector("[aria-invalid='true']")
        first?.focus()
    }

    /**
     * Moving the start moves the end with it, keeping the duration.
     *
     * The behaviour every other calendar has: dragging a 10:00–11:00 meeting to
     * 14:00 makes it 14:00–15:00, not 14:00–11:00. Without it the commonest
     * edit there is — "same meeting, later" — silently produces an end before
     * the start, and the user is then told off for a number they never touched.
     *
     * Only on the start, and only when the end is not already behind it: an end
     * the user has just corrected must not be dragged away from them.
     */
    shiftEnd(event) {
        if (!this.hasStartsTarget || !this.hasEndsTarget) {
            return
        }

        if (event.target !== this.startsTarget) {
            return
        }

        const previous = this.previousStart
        const next = this.startsTarget.value

        this.previousStart = next

        if ("" === previous || "" === next || "" === this.endsTarget.value) {
            return
        }

        // An end that was ALREADY behind the start is being argued about, and
        // dragging it along would preserve exactly the state the user is trying
        // to get out of — the same negative duration, moved. Left alone, so
        // that correcting the start is one of the two ways to fix it.
        if (this.endsTarget.value < previous) {
            return
        }

        const before = new Date(previous)
        const after = new Date(next)
        const end = new Date(this.endsTarget.value)

        if (Number.isNaN(before.getTime()) || Number.isNaN(after.getTime()) || Number.isNaN(end.getTime())) {
            return
        }

        // Parsed and re-formatted as LOCAL wall time, never through toISOString():
        // the fields carry a wall clock with no zone, and a round trip through
        // UTC would move every value by the reader's offset.
        const moved = new Date(end.getTime() + (after.getTime() - before.getTime()))

        this.endsTarget.value = this.constructor.asLocalInput(moved)

        this.clearFieldError(this.endsTarget)
    }

    // ── Private ─────────────────────────────────────────────────────────────

    static asLocalInput(date) {
        const pad = (n) => String(n).padStart(2, "0")

        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
            + `T${pad(date.getHours())}:${pad(date.getMinutes())}`
    }

    /**
     * Say one field is wrong, in the three places a field can say so, and
     * answer with the sentence for the summary.
     */
    markInvalid(field, errorElement) {
        const message = field.dataset.errorMessage ?? ""

        field.setAttribute("aria-invalid", "true")
        field.classList.remove("border-line")
        field.classList.add("border-danger")

        if (errorElement) {
            errorElement.textContent = message
            errorElement.classList.remove("hidden")
        }

        return message
    }

    clearFieldError(field) {
        if (!(field instanceof HTMLElement) || "true" !== field.getAttribute("aria-invalid")) {
            return
        }

        field.removeAttribute("aria-invalid")
        field.classList.remove("border-danger")
        field.classList.add("border-line")

        const errorElement = document.getElementById(field.getAttribute("aria-describedby") ?? "")

        if (errorElement) {
            errorElement.textContent = ""
            errorElement.classList.add("hidden")
        }

        // The summary is about the form, so it goes when the last field does.
        if (!this.element.querySelector("[aria-invalid='true']")) {
            this.clearSummary()
        }
    }

    showSummary(messages) {
        if (!this.hasSummaryTarget) {
            return
        }

        // Rebuilt rather than appended to, so a second attempt does not stack a
        // second copy of the same complaint under the first.
        this.summaryListTarget.replaceChildren(
            ...messages.filter(Boolean).map((message) => {
                const line = document.createElement("p")

                line.className = "leading-snug"
                line.textContent = message

                return line
            }),
        )

        this.summaryTarget.classList.remove("hidden")
        this.summaryTarget.classList.add("flex")
    }

    clearSummary() {
        if (!this.hasSummaryTarget) {
            return
        }

        this.summaryTarget.classList.add("hidden")
        this.summaryTarget.classList.remove("flex")
        this.summaryListTarget.replaceChildren()
    }
}
