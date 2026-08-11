import { Controller } from "@hotwired/stimulus"

/**
 * Boolean condition-tree editor for mail rules.
 *
 * The tree is rendered as nested, indented groups rather than a canvas or a
 * two-pane arrangement. That is a deliberate mobile decision: vertical flow
 * needs no horizontal room, so a phone gets the *same* editor with tighter
 * indentation and a wrapped control row, not a cut-down one. Depth is shown by
 * a left rail rather than whitespace alone, which survives the narrower indent.
 *
 * The tree lives in JS and is serialised into a hidden input on submit, because
 * its shape is recursive and its depth is chosen by the author — something a
 * server-rendered form tree models badly. FilterAstValidator re-checks whatever
 * arrives; nothing here is trusted.
 *
 * Two feedback affordances hang off the same debounce: a plain-English sentence
 * and a live count of matching mail. The count is what makes a filter something
 * you can see rather than something you write and hope about.
 */

const OPERATORS = [
    { value: "AND", labelKey: "all" },
    { value: "OR", labelKey: "any" },
    { value: "NOT", labelKey: "none" },
]

/** Field kind decides which input is rendered and how the value is coerced. */
const FIELDS = {
    from: "text", to: "text", cc: "text", bcc: "text",
    subject: "text", body: "text", text: "text",
    filename: "text", listId: "text",
    hasLabel: "label", notLabel: "label",
    minSize: "number", maxSize: "number",
    before: "date", after: "date",
    hasAttachment: "bool",
    hasKeyword: "keyword", notKeyword: "keyword",
}

const KEYWORDS = ["$seen", "$flagged", "$draft", "$answered"]

export default class extends Controller {
    static targets = ["tree", "conditions", "actions", "actionList", "summary", "count", "form"]
    static values = {
        conditions: Object,
        actions: Array,
        labels: Array,
        integrations: Array,
        previewUrl: String,
        csrf: String,
        /** Open a fresh editor with one condition row to fill in. */
        seedCondition: Boolean,
        i18n: Object,
    }

    connect() {
        this.tree = this._seed(this.conditionsValue)
        this.actions = Array.isArray(this.actionsValue) ? [...this.actionsValue] : []

        this.render()
        this.renderActions()
        this.schedulePreview()
    }

    disconnect() {
        clearTimeout(this._previewTimer)
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    render() {
        this.treeTarget.replaceChildren(this._group(this.tree, [], 0))
        this.serialise()
    }

    /**
     * A group: operator selector, its children, and the two add buttons.
     * `path` addresses this node from the root so handlers can mutate in place
     * without needing node identity.
     */
    _group(node, path, depth) {
        const wrap = document.createElement("div")
        wrap.className =
            depth === 0
                ? "rounded-lg border border-line bg-sunken/40"
                : "rounded-lg border border-line bg-sunken/40 mt-2"

        const head = document.createElement("div")
        head.className = "flex flex-wrap items-center gap-2 px-3 py-2 border-b border-line"

        const seg = document.createElement("div")
        seg.className = "inline-flex rounded-lg border border-field overflow-hidden"

        for (const op of OPERATORS) {
            const b = document.createElement("button")
            b.type = "button"
            b.textContent = this._t(op.labelKey)
            b.className =
                "px-2.5 h-7 text-xs font-medium transition-colors cursor-pointer " +
                (node.operator === op.value
                    ? "bg-accent text-accent-ink"
                    : "text-ink-soft hover:bg-hover")
            b.addEventListener("click", () => {
                this._at(path).operator = op.value
                this.render()
                this.schedulePreview()
            })
            seg.append(b)
        }

        head.append(seg)

        const hint = document.createElement("span")
        hint.className = "text-xs text-ink-faint"
        hint.textContent = this._t("of_the_following")
        head.append(hint)

        const spacer = document.createElement("div")
        spacer.className = "flex-1"
        head.append(spacer)

        head.append(this._smallButton("fa-plus", this._t("add_condition"), () => {
            this._at(path).conditions.push({ field: "subject", value: "" })
            this.render()
            this.schedulePreview()
        }))

        // Depth is capped well below the validator's limit: past three levels a
        // nested filter is easier to express as two rules.
        if (depth < 3) {
            head.append(this._smallButton("fa-code-branch", this._t("add_group"), () => {
                this._at(path).conditions.push({ operator: "AND", conditions: [{ field: "subject", value: "" }] })
                this.render()
                this.schedulePreview()
            }))
        }

        if (depth > 0) {
            head.append(this._smallButton("fa-xmark", this._t("remove_group"), () => {
                this._removeAt(path)
                this.render()
                this.schedulePreview()
            }, true))
        }

        wrap.append(head)

        const body = document.createElement("div")
        // The left rail is what carries depth once the indent shrinks on narrow
        // screens; pl-2 on mobile, pl-4 from sm up.
        body.className = "px-2 sm:px-3 py-2 space-y-2"

        node.conditions.forEach((child, i) => {
            const childPath = [...path, i]
            const railed = document.createElement("div")
            railed.className = depth > 0 ? "border-l-2 border-line/70 pl-2 sm:pl-3" : ""
            railed.append(
                child.operator ? this._group(child, childPath, depth + 1) : this._condition(child, childPath),
            )
            body.append(railed)
        })

        // Removing the last condition is a decision, not an accident, so the
        // empty state says what the rule now does rather than sitting blank.
        if (node.conditions.length === 0) {
            const empty = document.createElement("p")
            empty.className = "px-1 py-1 text-xs text-ink-faint"
            empty.textContent = this._t(depth === 0 ? "no_conditions" : "empty_group")
            body.append(empty)
        }

        wrap.append(body)

        return wrap
    }

    _condition(node, path) {
        const row = document.createElement("div")
        row.className = "flex flex-wrap items-center gap-2"

        const field = this._selectElement("w-auto min-w-[8.5rem]")

        for (const name of Object.keys(FIELDS)) {
            const o = document.createElement("option")
            o.value = name
            o.textContent = this._t(`field.${name}`)
            o.selected = node.field === name
            field.append(o)
        }

        field.addEventListener("change", () => {
            const target = this._at(path)
            target.field = field.value
            target.value = this._defaultFor(field.value)
            this.render()
            this.schedulePreview()
        })

        row.append(field)
        row.append(this._valueInput(node, path))

        row.append(this._smallButton("fa-xmark", this._t("remove_condition"), () => {
            this._removeAt(path)
            this.render()
            this.schedulePreview()
        }, true))

        return row
    }

    _valueInput(node, path) {
        const kind = FIELDS[node.field] ?? "text"
        const commit = (value) => {
            this._at(path).value = value
            this.serialise()
            this.schedulePreview()
        }

        if (kind === "bool" || kind === "keyword" || kind === "label") {
            const select = this._selectElement("flex-1 min-w-[9rem]")

            const options =
                kind === "bool"
                    ? [{ v: true, t: this._t("yes") }, { v: false, t: this._t("no") }]
                    : kind === "keyword"
                      ? KEYWORDS.map((k) => ({ v: k, t: this._t(`keyword.${k.slice(1)}`) }))
                      : this.labelsValue.map((l) => ({ v: l.id, t: l.name }))

            for (const opt of options) {
                const o = document.createElement("option")
                o.value = String(opt.v)
                o.textContent = opt.t
                o.selected = String(node.value) === String(opt.v)
                select.append(o)
            }

            select.addEventListener("change", () => {
                commit(kind === "bool" ? select.value === "true" : kind === "label" ? Number(select.value) : select.value)
            })

            return select
        }

        const input = document.createElement("input")
        input.className = this._inputClass() + " flex-1 min-w-[9rem]"
        // Names the condition's value field, so it can be found without asking
        // for "the text input in this row". The row also contains the search
        // box Tom Select injects into any select with more than a handful of
        // options — an unnamed, unlabelled <input type="text"> belonging to the
        // widget, not to the rule — and a positional query over both picks
        // whichever the widget happened to render first.
        input.dataset.ruleValue = ""
        input.type = kind === "number" ? "number" : kind === "date" ? "date" : "text"
        input.value = node.value ?? ""
        input.placeholder = this._t(`placeholder.${kind}`)
        input.addEventListener("input", () => {
            commit(kind === "number" ? Number(input.value) : input.value)
        })

        return input
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    renderActions() {
        this.actionListTarget.replaceChildren()

        this.actions.forEach((action, i) => {
            const row = document.createElement("div")
            row.className = "flex flex-wrap items-center gap-2"

            const select = this._selectElement("w-auto min-w-[10rem]")

            for (const type of this.i18nValue.actionTypes) {
                const o = document.createElement("option")
                o.value = type
                o.textContent = this._t(`action.${type}`)
                o.selected = action.type === type
                select.append(o)
            }

            select.addEventListener("change", () => {
                this.actions[i] = { type: select.value }

                if (select.value === "applyLabel" || select.value === "removeLabel") {
                    this.actions[i].labelId = this._assignableLabels()[0]?.id
                }

                if (select.value === "saveToIntegration") {
                    this.actions[i].integrationId = this.integrationsValue[0]?.id
                }

                this.renderActions()
                this.serialise()
                this.schedulePreview()
            })

            row.append(select)

            if (action.type === "applyLabel" || action.type === "removeLabel") {
                const label = this._selectElement("flex-1 min-w-[9rem]")

                // System labels are excluded here but not from conditions:
                // "has label Inbox" is a sensible test, while "apply label
                // Inbox" is not — that is what the archive action means.
                for (const l of this._assignableLabels()) {
                    const o = document.createElement("option")
                    o.value = String(l.id)
                    o.textContent = l.name
                    o.selected = Number(action.labelId) === l.id
                    label.append(o)
                }

                label.addEventListener("change", () => {
                    this.actions[i].labelId = Number(label.value)
                    this.serialise()
                    this.schedulePreview()
                })

                row.append(label)
            }

            if (action.type === "saveToIntegration") {
                if (0 === this.integrationsValue.length) {
                    // Nothing to save to. Said plainly here rather than
                    // rendering an empty select that would silently store no
                    // target and do nothing at run time.
                    const warn = document.createElement("span")
                    warn.className = "text-xs text-danger"
                    warn.textContent = this._t("no_integrations")
                    row.append(warn)
                } else {
                    const target = this._selectElement("flex-1 min-w-[9rem]")

                    for (const integration of this.integrationsValue) {
                        const o = document.createElement("option")
                        o.value = String(integration.id)
                        o.textContent = integration.name
                        o.selected = Number(action.integrationId) === integration.id
                        target.append(o)
                    }

                    target.addEventListener("change", () => {
                        this.actions[i].integrationId = Number(target.value)
                        this.serialise()
                        this.schedulePreview()
                    })

                    row.append(target)
                }
            }

            row.append(this._smallButton("fa-xmark", this._t("remove_action"), () => {
                this.actions.splice(i, 1)
                this.renderActions()
                this.serialise()
                this.schedulePreview()
            }, true))

            this.actionListTarget.append(row)
        })
    }

    addAction() {
        const assignable = this._assignableLabels()

        // With no user labels to apply, "apply label" would be an action that
        // cannot be completed — offer something that works instead.
        this.actions.push(
            assignable.length > 0
                ? { type: "applyLabel", labelId: assignable[0].id }
                : { type: "markRead" },
        )
        this.renderActions()
        this.serialise()
        this.schedulePreview()
    }

    // ── Feedback ────────────────────────────────────────────────────────────

    schedulePreview() {
        clearTimeout(this._previewTimer)
        this.countTarget.dataset.state = "pending"
        this._previewTimer = setTimeout(() => this.preview(), 450)
    }

    /**
     * Asks the server both questions at once: how many messages this catches,
     * and how it reads in words. The sentence is built server-side so there is
     * only one describer and it is translated — see FilterDescriber.
     */
    async preview() {
        const ast = this._toAst(this.tree)

        try {
            const response = await fetch(this.previewUrlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": this.csrfValue },
                // The account goes with it: a rule scoped to one account only
                // ever acts on that account, and a count over all of them
                // would be most wrong exactly where it matters most — a rule
                // with no conditions at all.
                body: JSON.stringify({
                    conditions: ast,
                    actions: this.actions,
                    account: this.element.querySelector('[name="account"]')?.value ?? "",
                }),
            })

            const data = await response.json()

            if (data.ok !== true) {
                this.countTarget.dataset.state = "error"
                this.countTarget.textContent = data.error ?? this._t("count.error")

                return
            }

            this.summaryTarget.textContent = data.description ?? ""
            this.countTarget.dataset.state = "ok"
            this.countTarget.textContent = this._plural(
                this._t(data.capped ? "count.capped" : "count.exact"),
                data.count,
            ).replace("%count%", String(data.count))
        } catch {
            // A failed probe is only a missing hint — never block the save.
            this.countTarget.dataset.state = "error"
            this.countTarget.textContent = this._t("count.error")
        }
    }

    // ── Serialisation ───────────────────────────────────────────────────────

    serialise() {
        this.conditionsTarget.value = JSON.stringify(this._toAst(this.tree))
        this.actionsTarget.value = JSON.stringify(this.actions)
    }

    /** Editor shape → the stored/compiled AST shape. */
    _toAst(node) {
        if (node.operator) {
            // An operator node with no conditions is invalid; the empty tree
            // is how "no conditions" is stored, and the compiler reads it as
            // every message.
            if (node.conditions.length === 0) {
                return {}
            }

            return {
                operator: node.operator,
                conditions: node.conditions.map((c) => this._toAst(c)),
            }
        }

        return { [node.field]: node.value }
    }

    /** Stored AST → editor shape, which carries field/value separately. */
    _seed(ast) {
        // An empty tree is a rule with no conditions: it acts on everything in
        // its scope. A brand-new editor gets one row to fill in instead, which
        // is what the vast majority of rules want — see seedConditionValue.
        if (!ast || Object.keys(ast).length === 0) {
            return {
                operator: "AND",
                conditions: this.seedConditionValue ? [{ field: "subject", value: "" }] : [],
            }
        }

        const fromAst = (n) => {
            if (n && typeof n === "object" && n.operator) {
                return { operator: n.operator, conditions: (n.conditions ?? []).map(fromAst) }
            }

            const [field, value] = Object.entries(n ?? {})[0] ?? ["subject", ""]

            return { field, value }
        }

        const seeded = fromAst(ast)

        // The root is always a group, so the operator control has somewhere to
        // live even for a brand-new single-condition rule.
        return seeded.operator ? seeded : { operator: "AND", conditions: [seeded] }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Labels a rule may apply or remove — user labels only. */
    _assignableLabels() {
        return this.labelsValue.filter((l) => l.system !== true)
    }

    _at(path) {
        return path.reduce((node, i) => node.conditions[i], this.tree)
    }

    _removeAt(path) {
        const parentPath = path.slice(0, -1)
        const parent = this._at(parentPath)
        parent.conditions.splice(path[path.length - 1], 1)

        if (parent.conditions.length > 0) {
            return
        }

        // A nested group with nothing left in it is a tree the validator
        // rejects and a box on screen that means nothing, so it goes too.
        // The root is different: empty there is the rule that acts on
        // everything, which is exactly what removing the last condition is
        // for.
        if (parentPath.length > 0) {
            this._removeAt(parentPath)
        }
    }

    _defaultFor(field) {
        const kind = FIELDS[field]

        if (kind === "bool") return true
        if (kind === "keyword") return KEYWORDS[0]
        if (kind === "label") return this.labelsValue[0]?.id
        if (kind === "number") return 0

        return ""
    }

    _smallButton(icon, title, onClick, danger = false) {
        const b = document.createElement("button")
        b.type = "button"
        b.title = title
        b.setAttribute("aria-label", title)
        b.className =
            "shrink-0 w-7 h-7 flex items-center justify-center rounded-lg text-xs transition-colors cursor-pointer " +
            (danger
                ? "text-ink-faint hover:text-danger hover:bg-danger-soft"
                : "text-ink-faint hover:text-ink hover:bg-hover")
        b.innerHTML = `<i class="fa-solid ${icon}" aria-hidden="true"></i>`
        b.addEventListener("click", onClick)

        return b
    }

    _inputClass() {
        return "h-8 rounded-lg border border-field bg-field px-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent"
    }

    /**
     * A <select> that ui--select will pick up.
     *
     * Every other select in the app is written in Twig and gets these
     * attributes from the _select.html.twig macro. This editor builds its rows
     * imperatively, so it has to stamp them itself — Stimulus watches the DOM,
     * so an element created here connects as soon as it is appended.
     *
     * `select-field` rather than _inputClass(): it is the same box, but it is
     * also the class the widget's stylesheet knows to undo once it has copied
     * the list onto its wrapper. Left as _inputClass(), the wrapper would draw
     * a second border around the control's own.
     */
    _selectElement(extraClass = "") {
        const select = document.createElement("select")
        select.className = `select-field h-8 text-xs ${extraClass}`.trim()
        // setAttribute, not dataset: the double dash in the identifier does not
        // survive the round trip through dataset's camel-casing.
        select.setAttribute("data-controller", "ui--select")
        select.setAttribute(
            "data-ui--select-i18n-value",
            JSON.stringify({ noResults: this._t("selectNoResults") }),
        )

        return select
    }

    _t(key) {
        return this.i18nValue[key] ?? key
    }

    /**
     * Picks the singular or plural half of a "one|many" string.
     *
     * The count changes as the rule is typed and the number is substituted
     * here rather than fetched, so the translation has to arrive carrying both
     * shapes — hence the pipe, which is Symfony's own separator. A string
     * without one is returned untouched, so a translation that has not been
     * split yet still reads.
     */
    _plural(message, count) {
        const forms = message.split("|")

        return forms.length < 2 ? message : (count === 1 ? forms[0] : forms[1])
    }
}
