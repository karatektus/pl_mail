import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import {
    chip,
    chipify,
    describe,
    fillMarkers,
    fillTokens,
    parseToken,
    recipientValues,
    signatureArgument,
    unchipped,
    writeToken,
} from "../../compose/template_variables.js";

/** Zero-width space: the editable position kept beside a chip. See #padChips(). */
const PAD = "\u200b";

/**
 * Is this a text node with something in it?
 *
 * "With something in it" is the half that matters. Range#insertNode splits the
 * text node it lands in and leaves an EMPTY one behind when the caret was at
 * the end, so a chip inserted at the end of a line has a text node after it —
 * one that holds no position a caret can take.
 */
function hasText(node) {
    return Node.TEXT_NODE === node?.nodeType && "" !== node.nodeValue;
}

/**
 * Variables in the template editor: chips for the tokens in the text, a menu to
 * add one, and the settings of the two kinds that have any.
 *
 * WHAT IS STORED AND WHAT IS SHOWN. The server keeps a variable as the text
 * `{{date|offset=+7d}}` (see App\Entity\Template\MailTemplate for why). This
 * controller is the only thing that ever shows it differently: on connect every
 * token in the subject and the body becomes a chip, and on submit every chip
 * becomes its token again in the two hidden inputs the form posts. Between
 * those two moments the token lives in the chip's `data-pl-token` and the
 * chip's text is only a description of it.
 *
 * Shares the form with `compose--compose-toolbar`, which does the formatting.
 * The two touch the same contenteditable and nothing else of each other's.
 *
 * Values
 *   variables   string[]  the names that are variables; anything else between
 *                         braces is left as text
 *   previewUrl  String    POST {subject, body, location} → {ok, subject, html}
 *   dateUrl     String    POST {offset, format} → {text}
 *   i18n        Object    the chip wording describe() takes (labels,
 *                         formats, units, signatures), plus sampleRecipient,
 *                         previewUnavailable, noSubject
 *
 * Targets
 *   subject, body               the two editable fields
 *   subjectInput, bodyInput     what the form posts
 *   location                    the Folder select, read for the preview
 *   menu                        the "Insert variable" <details>
 *   inspector                   the strip holding the two groups below
 *   dateFields, amount, unit, direction, format, pattern, patternRow,
 *   patternHint, example        settings of a date variable
 *   signatureFields, signature  settings of a signature variable
 *   preview, previewSubject, previewBody
 */
export default class extends Controller {
    static targets = [
        "subject", "body", "subjectInput", "bodyInput", "location", "menu",
        "inspector", "dateFields", "amount", "unit", "direction", "format",
        "pattern", "patternRow", "patternHint", "example",
        "signatureFields", "signature",
        "preview", "previewSubject", "previewBody",
    ];

    static values = {
        variables: Array,
        previewUrl: String,
        dateUrl: String,
        i18n: Object,
    };

    /** The chip whose settings the inspector is showing, if any. */
    #selected = null;

    /** Where the caret last was inside the subject or the body. */
    #caret = null;

    #exampleTimer = null;

    connect() {
        const describe = (token) => this.#describe(token);

        chipify(this.subjectTarget, this.variablesValue, describe);
        chipify(this.bodyTarget, this.variablesValue, describe);
        this.#padChips();

        this._boundSelection = this.#rememberCaret.bind(this);
        this._boundClick = this.#handleClick.bind(this);
        this._boundPad = this.#padChips.bind(this);

        document.addEventListener("selectionchange", this._boundSelection);
        this.element.addEventListener("click", this._boundClick);
        this.element.addEventListener("input", this._boundPad);
    }

    disconnect() {
        document.removeEventListener("selectionchange", this._boundSelection);
        this.element.removeEventListener("click", this._boundClick);
        this.element.removeEventListener("input", this._boundPad);
        clearTimeout(this.#exampleTimer);
    }

    // ── Actions ───────────────────────────────────────────────────────────

    /**
     * Write both fields into the inputs the form posts, chips as tokens.
     *
     * On `submit`, which fires before Turbo reads the form — the listener is
     * on the form itself and Turbo's is on the document.
     */
    store() {
        this.subjectInputTarget.value = unchipped(this.subjectTarget).textContent
            .replaceAll(PAD, "").replace(/\s+/g, " ").trim();
        this.bodyInputTarget.value = unchipped(this.bodyTarget).innerHTML.replaceAll(PAD, "");
    }

    /**
     * A menu button must not take the caret with it.
     *
     * Without this the mousedown moves focus to the button, the selection
     * leaves the field, and the variable is inserted at the end of the body
     * whatever the caret was in — including when it was in the subject.
     */
    keepCaret(event) {
        event.preventDefault();
    }

    /** Menu: put a variable where the caret is. */
    insert(event) {
        const name = event.currentTarget.dataset.variable;
        const element = chip({ name, args: {} }, (token) => this.#describe(token));

        const range = this.#insertionRange();

        range.deleteContents();
        range.insertNode(element);

        // The caret directly after it, and nothing added. A space put there
        // "to be helpful" ends up in the mail: "Hi {first name} ," is what a
        // template written as chip-then-comma stored, because the comma was
        // typed after a space nobody typed.
        const after = document.createRange();
        after.setStartAfter(element);
        after.collapse(true);

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(after);

        this.#padChips();
        this.menuTarget.open = false;
        this.#select(element);
    }

    /** The subject is one line. */
    singleLine(event) {
        if ("Enter" === event.key) {
            event.preventDefault();
        }
    }

    /** And takes pasted text as text: a subject has no formatting to keep. */
    pastePlain(event) {
        event.preventDefault();

        const text = (event.clipboardData?.getData("text/plain") ?? "").replace(/\s+/g, " ");
        const selection = window.getSelection();

        if (0 === selection.rangeCount) {
            return;
        }

        const range = selection.getRangeAt(0);
        range.deleteContents();
        range.insertNode(document.createTextNode(text));
        range.collapse(false);
    }

    /** Inspector: a control changed, so the selected chip's token did. */
    update() {
        if (null === this.#selected) {
            return;
        }

        const token = parseToken(this.#selected.dataset.plToken);

        if (null === token) {
            return;
        }

        if ("date" === token.name) {
            token.args = this.#dateArguments();
            this.#syncPatternRow();
            this.#refreshExample(token.args);
        }

        if ("signature" === token.name) {
            token.args = this.#signatureArguments();
        }

        this.#selected.dataset.plToken = writeToken(token);
        this.#selected.textContent = this.#describe(token);
    }

    /**
     * Show what the template on screen would insert.
     *
     * The server fills in what it knows and leaves the recipient open, exactly
     * as it does for the compose window; the sample recipient is then filled in
     * here by the same function the window uses. So the preview cannot show a
     * first name the composer would not have found.
     */
    async preview() {
        this.store();

        let payload = null;

        try {
            const response = await fetch(this.previewUrlValue, {
                method: "POST",
                headers: jsonCsrfHeaders({ Accept: "application/json" }),
                body: JSON.stringify({
                    subject: this.subjectInputTarget.value,
                    body: this.bodyInputTarget.value,
                    location: this.hasLocationTarget ? this.locationTarget.value : "root",
                }),
            });

            payload = response.ok ? await response.json() : null;
        } catch {
            payload = null;
        }

        this.previewTarget.hidden = false;

        if (true !== payload?.ok) {
            this.previewSubjectTarget.textContent = "";
            this.previewBodyTarget.textContent = this.i18nValue.previewUnavailable ?? "";

            return;
        }

        const sample = recipientValues(this.i18nValue.sampleRecipient ?? "");

        this.previewSubjectTarget.textContent =
            fillTokens(payload.subject, sample) || (this.i18nValue.noSubject ?? "");

        // The server's answer is the sanitised body with values in it — the
        // same HTML the compose window is handed and injects.
        this.previewBodyTarget.innerHTML = payload.html;
        fillMarkers(this.previewBodyTarget, sample);

        this.previewTarget.scrollIntoView({ block: "nearest" });
    }

    // ── Private ───────────────────────────────────────────────────────────

    /** How a token reads on its chip — the shared wording, see describe(). */
    #describe(token) {
        return describe(token, this.i18nValue);
    }

    #handleClick(event) {
        const element = event.target instanceof Element ? event.target.closest("[data-pl-token]") : null;

        if (null !== element && this.element.contains(element)) {
            this.#select(element);

            return;
        }

        // A click in either field that is not on a chip puts the settings away;
        // a click on the settings themselves must not.
        if (this.subjectTarget.contains(event.target) || this.bodyTarget.contains(event.target)) {
            this.#select(null);
        }
    }

    #select(element) {
        this.#selected?.removeAttribute("data-selected");
        this.#selected = element;

        const token = null === element ? null : parseToken(element.dataset.plToken);
        const isDate = "date" === token?.name;
        const isSignature = "signature" === token?.name;

        this.inspectorTarget.hidden = false === (isDate || isSignature);
        this.dateFieldsTarget.hidden = false === isDate;
        this.signatureFieldsTarget.hidden = false === isSignature;

        if (null === token) {
            return;
        }

        element.setAttribute("data-selected", "");

        if (true === isDate) {
            this.#loadDate(token.args);
        }

        if (true === isSignature) {
            const argument = signatureArgument(token);
            const known = [...this.signatureTarget.options].some((option) => option.value === argument);

            this.signatureTarget.value = true === known ? argument : "";
        }
    }

    #loadDate(args) {
        const offset = /^([+-]?)(\d+)([dwm])$/.exec(args.offset ?? "");

        this.amountTarget.value = null === offset ? "0" : String(Number(offset[2]));
        this.unitTarget.value = null === offset ? "d" : offset[3];
        this.directionTarget.value = "-" === offset?.[1] ? "-" : "+";

        const format = args.format ?? "";

        if (format.startsWith("pattern:")) {
            this.formatTarget.value = "custom";
            this.patternTarget.value = format.slice(8);
        } else {
            this.formatTarget.value = "" === format ? "long" : format;
            this.patternTarget.value = "";
        }

        this.#syncPatternRow();
        this.#refreshExample(this.#dateArguments());
    }

    /** The date controls, as token arguments. Defaults are left out. */
    #dateArguments() {
        const args = {};
        const amount = Math.max(0, Math.min(9999, Math.trunc(Number(this.amountTarget.value) || 0)));

        if (0 !== amount) {
            args.offset = `${this.directionTarget.value}${amount}${this.unitTarget.value}`;
        }

        if ("custom" === this.formatTarget.value) {
            // Braces and pipes are the notation's own; a pattern cannot hold
            // them, and nothing a date is written with needs them.
            const pattern = this.patternTarget.value.replace(/[{}|]/g, "").trim();

            if ("" !== pattern) {
                args.format = `pattern:${pattern}`;
            }
        } else if ("long" !== this.formatTarget.value) {
            args.format = this.formatTarget.value;
        }

        return args;
    }

    #signatureArguments() {
        const [key, value] = this.signatureTarget.value.split("=");

        return key && value ? { [key]: value } : {};
    }

    #syncPatternRow() {
        const custom = "custom" === this.formatTarget.value;

        this.patternRowTarget.hidden = false === custom;
        this.patternHintTarget.hidden = false === custom;
    }

    /**
     * Ask the server to write the date, shortly after the last change.
     *
     * Debounced because the offset is a number field and the pattern a text
     * field: without it every keystroke is a request, and the answers can
     * arrive out of order and leave the example showing a stale one.
     */
    #refreshExample(args) {
        clearTimeout(this.#exampleTimer);

        this.#exampleTimer = setTimeout(async () => {
            try {
                const response = await fetch(this.dateUrlValue, {
                    method: "POST",
                    headers: jsonCsrfHeaders({ Accept: "application/json" }),
                    body: JSON.stringify({ offset: args.offset ?? "", format: args.format ?? "" }),
                });

                if (response.ok) {
                    this.exampleTarget.textContent = (await response.json()).text ?? "";
                }
            } catch {
                // The example is a convenience; the chip already holds the choice.
            }
        }, 200);
    }

    /**
     * Keep a place to type on both sides of every chip.
     *
     * A chip is `contenteditable="false"`, and Chromium will not put a caret
     * between a non-editable element and the edge of its block: with a chip as
     * the last thing on a line, clicking after it shows a caret and typing does
     * nothing — the keystrokes are simply dropped. Measured, not guessed: a
     * template ending "…settle it by {date}" could not be given its question
     * mark. A zero-width space beside the chip is a text position the caret can
     * hold, and store() removes every one of them, so none reaches the server.
     *
     * On every input as well as on connect and insert, because deleting the
     * text after a chip is how a chip becomes the last thing on a line.
     */
    #padChips() {
        for (const field of [this.subjectTarget, this.bodyTarget]) {
            field.querySelectorAll("[data-pl-token]").forEach((element) => {
                if (false === hasText(element.nextSibling)) {
                    element.after(document.createTextNode(PAD));
                }

                if (false === hasText(element.previousSibling)) {
                    element.before(document.createTextNode(PAD));
                }
            });
        }
    }

    #rememberCaret() {
        const selection = window.getSelection();

        if (null === selection || 0 === selection.rangeCount) {
            return;
        }

        const range = selection.getRangeAt(0);
        const node = range.commonAncestorContainer;

        if (this.subjectTarget.contains(node) || this.bodyTarget.contains(node)) {
            this.#caret = range.cloneRange();
        }
    }

    /** Where a new variable goes: the remembered caret, else the end of the body. */
    #insertionRange() {
        if (null !== this.#caret && this.element.contains(this.#caret.commonAncestorContainer)) {
            return this.#caret;
        }

        const range = document.createRange();

        range.selectNodeContents(this.bodyTarget);
        range.collapse(false);

        return range;
    }
}
