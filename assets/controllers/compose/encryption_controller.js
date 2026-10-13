import { Controller } from "@hotwired/stimulus";
import { csrfToken } from "../../csrf.js";
import { keyring } from "../../crypto/keyring.js";
import { buildEntity, extractInlineImages, fromDataUri } from "../../crypto/mime.js";
import { encryptEntity, openDraft, openFile, ownKeyEntryFor, sealDraft, sealFile, signEntity } from "../../crypto/compose.js";
import { openEncrypted } from "../../crypto/message.js";

/**
 * Encryption in the compose window: whether this message will go out
 * encrypted, keeping the draft sealed while it is written, and building the
 * message in the browser when it is sent.
 *
 * Decided as the recipients are typed (phase 5 of the concept in issue #28):
 *
 *   - every recipient has a key        → encrypted, a green lock in the header
 *   - some have one, some do not       → a quiet note that it goes unencrypted
 *                                        to everybody, or a refusal when the
 *                                        person said so (User::$blockPartialEncryption)
 *   - none has one                     → plain mail, as before
 *
 * "Encrypt" and "Sign" in the More options menu override that for one
 * message. A reply to an encrypted message is encrypted from the start, and
 * its quote is decrypted here, where the key is.
 *
 * Once a draft is encrypted it is sealed: every save encrypts what was typed
 * to the person's own keys and sends the body fields empty
 * (App\Service\Crypto\SealedDrafts), attachments are encrypted before they are
 * uploaded, and pictures stay inside the draft. It stays sealed even if the
 * message later goes out unencrypted, because nothing but this browser can
 * open it again; the message is then built here as plain mail.
 *
 * compose--compose asks this controller three things at the moments that
 * matter — prepareDraft() before an autosave, mustBuild()/build() before a
 * send, sealUploads()/isSealing() before a file goes up — and otherwise
 * leaves it alone. A window whose person has no open keyring behaves as it
 * always did, unless a draft or a recipient needs encryption it cannot give.
 */
export default class extends Controller {
    static targets = ["indicator", "hint", "fields", "encryptToggle", "encryptButton", "signToggle", "aiAssist"];

    static values = {
        keyring: Object,
        recipientsUrl: String,
        sealedDraftUrl: String,
        rawUrl: String,
        attachmentUrl: String,
        mode: { type: String, default: "auto" },
        sealed: Boolean,
        signPlain: Boolean,
        blockPartial: Boolean,
        attachKey: Boolean,
        messages: Object,
    };

    #ring = null;
    #status = "pending";
    #ready = true;
    #info = new Map();
    #sender = null;
    #sealed = false;
    #mode = "auto";
    #signMode = "auto";
    #timer = null;
    #onChange = null;

    connect() {
        this.#ring = keyring(this.keyringValue);
        this.#sealed = this.sealedValue;
        this.#mode = this.modeValue;
        this.#ready = "" === this.sealedDraftUrlValue;

        this.#onChange = (event) => {
            if (event.target instanceof HTMLSelectElement) {
                this.#scheduleRefresh();
            }
        };
        this.element.addEventListener("change", this.#onChange);

        this.#start();
    }

    disconnect() {
        this.element.removeEventListener("change", this.#onChange);
        clearTimeout(this.#timer);
    }

    // ── Asked by compose--compose ───────────────────────────────────────────

    /**
     * Seal the draft into an autosave's form data, when it is to be sealed.
     *
     * @returns {Promise<boolean>} false when it must not be saved now
     */
    async prepareDraft(formData) {
        for (const name of ["sealed[entity]", "sealed[kind]", "sealed[fingerprints]", "sealed[signer]", "sealed[draft]"]) {
            formData.delete(name);
        }

        if (false === this.#ready) {
            return false;
        }

        // Still finding out whether the keyring is open. A draft that has to
        // be sealed waits for the answer; any other saves as it always did.
        if ("pending" === this.#status) {
            return false === this.isSealing();
        }

        if (false === this.isSealing()) {
            return true;
        }

        if ("open" !== this.#status) {
            this.#note(this.messagesValue.lockedNotSaved, true);

            return false;
        }

        const { html, text, plain } = this.#body();

        formData.set(this.#htmlInput().name, "");
        formData.set(this.#textInput().name, "");
        formData.set("sealed[draft]", await sealDraft(this.#ring, { html, text, plain }));
        this.#sealed = true;
        this.#paint();

        return true;
    }

    /** Whether this send needs the message built here first. */
    mustBuild() {
        return null !== this.#plan().kind || null !== this.#plan().blocked;
    }

    /**
     * Build the message and put it in the form, ready to submit.
     *
     * @throws {Error} with a reason explain() turns into words
     */
    async build() {
        await this.#refresh(true);

        const plan = this.#plan();

        if (null !== plan.blocked) {
            throw Object.assign(new Error(plan.blocked.reason), { emails: plan.blocked.emails });
        }

        if ("open" !== this.#status && ("encrypted" === plan.kind || "signed" === plan.kind || true === this.#sealed)) {
            throw new Error("locked");
        }

        const signer = this.#signer();

        if (null === signer && ("encrypted" === plan.kind || "signed" === plan.kind)) {
            throw new Error("no_own_key");
        }

        const { html, text, plain } = this.#body();
        const attachments = await this.#attachments(signer);
        const inline = true === plan.inline && 0 === attachments.length;
        const images = true === plain || null === html
            ? { html: null, related: [] }
            : await extractInlineImages(html, (src) => this.#loadImage(src));
        const entity = buildEntity({ html: images.html, text, attachments, related: images.related });

        const fields = { "sealed[kind]": plan.kind };

        if ("encrypted" === plan.kind) {
            const sealed = await encryptEntity({
                ring: this.#ring,
                entity,
                inlineText: inline ? text : null,
                recipientKeys: plan.emails.map((email) => this.#info.get(email)?.key?.publicKey).filter(Boolean),
                signer,
                hideRecipients: 0 < plan.bcc.length,
            });

            fields["sealed[entity]"] = sealed.entity;
            fields["sealed[fingerprints]"] = sealed.fingerprints.join(",");
            fields["sealed[signer]"] = signer.fingerprint;
        } else if ("signed" === plan.kind) {
            fields["sealed[entity]"] = await signEntity({ ring: this.#ring, entity, signer });
            fields["sealed[signer]"] = signer.fingerprint;
        } else {
            fields["sealed[entity]"] = entity;
        }

        if (true === this.isSealing()) {
            fields["sealed[draft]"] = await sealDraft(this.#ring, { html: html ?? "", text, plain });
        }

        // Encrypted mail leaves no readable body behind. Signed and plain mail
        // keep theirs: it is going out readable, and the Sent copy should be
        // searchable like any other.
        if ("encrypted" === plan.kind && true !== this.#sender?.keepsDecrypted) {
            this.#htmlInput().value = "";
            this.#textInput().value = "";
        }

        this.fieldsTarget.replaceChildren(...Object.entries(fields).map(([name, value]) => {
            const input = document.createElement("input");

            input.type = "hidden";
            input.name = name;
            input.value = value;

            return input;
        }));
    }

    /** Words for a reason build() threw. */
    explain(error) {
        const messages = this.messagesValue;
        const emails = (error?.emails ?? []).join(", ");

        switch (error?.message) {
            case "missing":
                return messages.missing.replace("%emails%", emails);
            case "partial":
                return messages.partialBlocked.replace("%emails%", emails);
            case "locked":
                return messages.locked;
            case "no_own_key":
                return messages.noOwnKey;
            default:
                return messages.failed;
        }
    }

    /** explain(), said in the window as well: the reason a send did not go. */
    report(error) {
        const text = this.explain(error);

        this.#note(text, true);

        return text;
    }

    /**
     * Whether what is typed here is kept sealed. Not for an account that keeps
     * decrypted mail on the server: its person chose to have the text there.
     */
    isSealing() {
        if (true === this.#sender?.keepsDecrypted && false === this.#sealed) {
            return false;
        }

        return true === this.#sealed || true === this.#plan().encrypt;
    }

    /**
     * Files, encrypted to the person's own keys for a sealed draft, or null to
     * upload them as they are.
     *
     * @param {File[]} files
     * @returns {Promise<?File[]>}
     */
    async sealUploads(files) {
        if (false === this.isSealing() || "open" !== this.#status) {
            return null;
        }

        return Promise.all(files.map(async (file) => new File(
            [await sealFile(this.#ring, new Uint8Array(await file.arrayBuffer()))],
            file.name,
            { type: file.type || "application/octet-stream" },
        )));
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    /** More options → Encrypt: on, then off, then back to deciding by itself. */
    toggleEncrypt() {
        this.#mode = "auto" === this.#mode ? (this.#plan().encrypt ? "off" : "on") : ("on" === this.#mode ? "off" : "on");
        this.#paint();
    }

    /** More options → Sign. */
    toggleSign() {
        this.#signMode = this.#plan().sign ? "off" : "on";
        this.#paint();
    }

    // ── Private ─────────────────────────────────────────────────────────────

    async #start() {
        try {
            this.#status = (await this.#ring.ensure()).status;
        } catch {
            this.#status = "broken";
        }

        if ("" !== this.sealedDraftUrlValue) {
            await this.#openSealedDraft();
        }

        await this.#openSealedQuotes();
        await this.#refresh(false);
    }

    /** The sealed draft, back into the editor. Nothing is saved until it is. */
    async #openSealedDraft() {
        if ("open" !== this.#status) {
            this.#note(this.messagesValue.lockedDraft, true);

            return;
        }

        try {
            const response = await fetch(this.sealedDraftUrlValue, { cache: "no-store" });

            if (false === response.ok) {
                throw new Error("no_draft");
            }

            const draft = await openDraft(this.#ring, await response.text());

            this.#textInput().value = draft.text;
            this.#htmlInput().value = draft.html;
            this.#editor().innerHTML = await this.#clean(draft.html);
            this.#ready = true;
        } catch {
            this.#note(this.messagesValue.failedDraft, true);
        }
    }

    /**
     * Quotes of encrypted mail: ReplyDraftBuilder left a marker where the
     * original goes, and the original is decrypted here.
     */
    async #openSealedQuotes() {
        const markers = this.#editor().querySelectorAll("[data-quote-sealed]");

        if (0 === markers.length || "open" !== this.#status) {
            return;
        }

        for (const marker of markers) {
            try {
                const response = await fetch(this.rawUrlValue.replace("__ID__", marker.dataset.quoteSealed), { cache: "no-store" });
                const opened = await openEncrypted(new Uint8Array(await response.arrayBuffer()), this.#ring);
                const holder = document.createElement("div");

                if (null !== opened.html) {
                    holder.innerHTML = await this.#clean(opened.html);
                } else {
                    holder.textContent = opened.text ?? "";
                    holder.innerHTML = holder.innerHTML.replace(/\n/g, "<br>");
                }

                marker.replaceWith(...holder.childNodes);
            } catch {
                // Left in place: the next window to open this draft tries again.
            }
        }

        this.#htmlInput().value = this.#editor().innerHTML;
    }

    #scheduleRefresh() {
        clearTimeout(this.#timer);
        this.#timer = setTimeout(() => this.#refresh(false), 300);
    }

    /**
     * Ask the server about the recipients, then repaint. With `force`, every
     * one of them again and a lookup for those without a key — the last word
     * before a message is encrypted.
     */
    async #refresh(force) {
        const emails = this.#recipients();
        const unknown = true === force ? emails : emails.filter((email) => false === this.#info.has(email));

        if (0 < unknown.length || null === this.#sender || true === force) {
            try {
                const response = await fetch(this.recipientsUrlValue, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken(), "X-Requested-With": "XMLHttpRequest" },
                    body: JSON.stringify({
                        emails: unknown,
                        lookup: "open" === this.#status && "off" !== this.#mode,
                        from: this.#fromToken(),
                    }),
                });

                if (true === response.ok) {
                    const answer = await response.json();

                    (answer.recipients ?? []).forEach((info) => this.#info.set(info.email, info));
                    this.#sender = answer.sender ?? null;
                }
            } catch {
                // No answer is no keys: the window behaves as it always did.
            }
        }

        this.#paint();
    }

    /**
     * What this message will be.
     *
     * @returns {{encrypt: boolean, sign: boolean, kind: ?string, blocked: ?{reason: string, emails: string[]}, partial: string[], inline: boolean, emails: string[], bcc: string[]}}
     */
    #plan() {
        const emails = this.#recipients();
        const bcc = this.#recipients(["bcc"]);
        const infos = emails.map((email) => this.#info.get(email) ?? null);
        const missing = emails.filter((email, index) => null === (infos[index]?.key ?? null));
        const withKey = emails.length - missing.length;
        const usable = "open" === this.#status || "locked" === this.#status || "broken" === this.#status;
        let encrypt = false;
        let blocked = null;
        let partial = [];

        if ("on" === this.#mode) {
            encrypt = true;
            blocked = 0 < missing.length ? { reason: "missing", emails: missing } : null;
        } else if ("auto" === this.#mode && true === usable && 0 < emails.length) {
            encrypt = 0 === missing.length && false === infos.some((info) => "never" === info?.encrypt);

            if (false === encrypt && 0 < withKey && 0 < missing.length) {
                partial = missing;

                if (true === this.blockPartialValue || true === infos.some((info) => "always" === info?.encrypt)) {
                    blocked = { reason: "partial", emails: missing };
                }
            }
        }

        const canSign = "open" === this.#status && null !== this.#signer();
        let sign = encrypt;

        if (false === encrypt) {
            sign = "on" === this.#signMode
                || ("auto" === this.#signMode && true === canSign
                    && (true === this.signPlainValue || 0 < withKey || infos.some((info) => "always" === info?.sign)));
        }

        const kind = true === encrypt ? "encrypted" : (true === sign ? "signed" : (true === this.#sealed ? "plain" : null));
        const inline = true === encrypt && 0 < emails.length
            && infos.every((info) => "pgp-inline" === info?.scheme || true === info?.own)
            && infos.some((info) => "pgp-inline" === info?.scheme);

        return { encrypt, sign, kind, blocked, partial, inline, emails, bcc };
    }

    /** The lock in the header, the note under it, the menu's ticks, the chips. */
    #paint() {
        const plan = this.#plan();
        const messages = this.messagesValue;

        if (true === this.hasIndicatorTarget) {
            this.indicatorTarget.hidden = false === plan.encrypt;
        }

        if (true === this.hasHintTarget) {
            const text = 0 < plan.partial.length && null === plan.blocked
                ? messages.partial.replace("%emails%", plan.partial.join(", "))
                : (null !== plan.blocked ? this.explain(Object.assign(new Error(plan.blocked.reason), { emails: plan.blocked.emails })) : "");

            this.hintTarget.textContent = text;
            this.hintTarget.hidden = "" === text;
            this.hintTarget.classList.toggle("text-danger", null !== plan.blocked);
        }

        this.encryptToggleTargets.forEach((button) => button.setAttribute("aria-checked", String(plan.encrypt)));
        this.encryptButtonTargets.forEach((button) => button.setAttribute("aria-pressed", String(plan.encrypt)));
        this.signToggleTargets.forEach((button) => button.setAttribute("aria-checked", String(plan.sign)));

        // The writing assistant reads the draft on the server. Not for this one.
        this.aiAssistTargets.forEach((element) => { element.hidden = true === this.isSealing(); });

        this.#paintChips();
    }

    #paintChips() {
        const addresses = new Map();

        for (const option of this.element.querySelectorAll("select[name*='Addresses'] option")) {
            addresses.set(option.value, optionAddress(option));
        }

        for (const item of this.element.querySelectorAll(".ts-control .item[data-value]")) {
            const email = addresses.get(item.dataset.value) ?? addressOf(item.dataset.value);
            const info = this.#info.get(email);
            let lock = item.querySelector("[data-encryption-lock]");

            if (null === (info?.key ?? null)) {
                lock?.remove();
                continue;
            }

            if (null === lock) {
                lock = document.createElement("i");
                lock.setAttribute("data-encryption-lock", "");
                lock.setAttribute("aria-hidden", "true");
                item.prepend(lock);
            }

            lock.className = `fa-solid ${true === info.changed ? "fa-triangle-exclamation text-warning" : "fa-lock text-success"} text-[10px] mr-1`;
            lock.title = true === info.changed ? this.messagesValue.keyChanged : this.messagesValue.hasKey;
        }
    }

    #note(text, problem) {
        if (true === this.hasHintTarget) {
            this.hintTarget.textContent = text;
            this.hintTarget.hidden = false;
            this.hintTarget.classList.toggle("text-danger", true === problem);
        }
    }

    /** Every address on the message, or only those in the named rows. */
    #recipients(rows = ["to", "cc", "bcc"]) {
        const emails = new Set();

        for (const select of this.element.querySelectorAll("select[name*='Addresses']")) {
            const row = /(to|cc|bcc)Addresses/i.exec(select.name)?.[1]?.toLowerCase();

            if (false === rows.includes(row)) {
                continue;
            }

            for (const option of select.selectedOptions) {
                const email = optionAddress(option);

                if ("" !== email) {
                    emails.add(email);
                }
            }
        }

        return [...emails];
    }

    #signer() {
        const fingerprint = String(this.#sender?.fingerprint ?? "").toLowerCase();
        const entries = this.#ring?.state?.addressKeys ?? [];

        return entries.find((entry) => fingerprint === String(entry.fingerprint).toLowerCase())
            ?? (null === this.#sender ? null : ownKeyEntryFor(this.#ring, this.#sender.email));
    }

    #body() {
        const plain = false === this.#textInput().hidden;
        const html = true === plain ? null : this.#editorHtml();
        const text = true === plain ? this.#textInput().value : textOf(html ?? "");

        return { html, text, plain };
    }

    /** The decrypted attachments, and the public key when it is to be attached. */
    async #attachments(signer) {
        const files = [];

        for (const chip of this.element.querySelectorAll("[data-compose-part]")) {
            const response = await fetch(this.attachmentUrlValue.replace("__ID__", chip.dataset.composePart), { cache: "no-store" });

            if (false === response.ok) {
                throw new Error("attachment");
            }

            let bytes = new Uint8Array(await response.arrayBuffer());

            if ("1" === chip.dataset.partSealed) {
                bytes = await openFile(this.#ring, bytes);
            }

            files.push({ filename: chip.dataset.partName, contentType: chip.dataset.partType, bytes });
        }

        if (true === this.attachKeyValue && null !== signer) {
            files.push({
                filename: `OpenPGP_0x${signer.fingerprint.slice(-16).toUpperCase()}.asc`,
                contentType: "application/pgp-keys",
                bytes: new TextEncoder().encode(signer.publicKey),
            });
        }

        return files;
    }

    /** An image in the editor as bytes: a data: URI, or one of the draft's own uploads. */
    async #loadImage(src) {
        const data = fromDataUri(src);

        if (null !== data) {
            return data;
        }

        let url;

        try {
            url = new URL(src, window.location.href);
        } catch {
            return null;
        }

        if (url.origin !== window.location.origin) {
            return null;
        }

        const response = await fetch(url, { cache: "no-store" });

        if (false === response.ok) {
            return null;
        }

        return {
            bytes: new Uint8Array(await response.arrayBuffer()),
            contentType: (response.headers.get("Content-Type") ?? "application/octet-stream").split(";")[0].trim(),
        };
    }

    /** Decrypted HTML, made fit for the editor — which is the app's own page. */
    async #clean(html) {
        const { default: DOMPurify } = await import("dompurify");

        return DOMPurify(window).sanitize(html, {
            FORBID_TAGS: ["style", "script", "iframe", "frame", "object", "embed", "form", "input", "button", "base", "meta", "link"],
            ADD_ATTR: ["data-quoted", "data-pl-signature", "data-quote-sealed", "data-pl-var", "data-pl-signature-pinned", "target"],
        });
    }

    #fromToken() {
        return this.element.querySelector("select[data-compose--compose-target='accountSelect']")?.value ?? null;
    }

    #editor() {
        return this.element.querySelector("[data-compose--compose-target='body']");
    }

    #editorHtml() {
        const toolbar = this.application.getControllerForElementAndIdentifier(this.element, "compose--compose-toolbar");

        return toolbar?._cleanHtml?.() ?? this.#editor().innerHTML;
    }

    #htmlInput() {
        return this.element.querySelector("[data-compose--compose-toolbar-target='hiddenInput']");
    }

    #textInput() {
        return this.element.querySelector("[data-compose--compose-target='plainBody']");
    }
}

/**
 * A recipient option's address. The value is a Contact id (or, for an address
 * typed just now, the address itself); the label is "Name <address>" or the
 * bare address — see ContactAutocompleteField.
 */
function optionAddress(option) {
    return addressOf(String(option.value).includes("@") ? option.value : option.textContent);
}

/** The address in "Name <address>" or a bare address, lower-cased. */
function addressOf(value) {
    const text = String(value ?? "").trim();
    const angled = /<([^>]+)>\s*$/.exec(text);

    return (null === angled ? text : angled[1]).trim().toLowerCase();
}

/** HTML as the text/plain alternative: line breaks where the blocks were. */
function textOf(html) {
    const doc = new DOMParser().parseFromString(`<body>${html}</body>`, "text/html");

    doc.body.querySelectorAll("br").forEach((br) => br.replaceWith("\n"));
    doc.body.querySelectorAll("p, div, li, blockquote, h1, h2, h3, h4, h5, h6, tr").forEach((block) => block.append("\n"));
    doc.body.querySelectorAll("blockquote").forEach((quote) => {
        quote.textContent = quote.textContent.replace(/^/gm, "> ");
    });

    return doc.body.textContent.replace(/\n{3,}/g, "\n\n").trim();
}
