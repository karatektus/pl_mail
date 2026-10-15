import { Controller } from "@hotwired/stimulus";
import { csrfToken } from "../../csrf.js";
import { keyring } from "../../crypto/keyring.js";
import { isPlaceholder, openEncrypted } from "../../crypto/message.js";

/**
 * Shows an encrypted message, OpenPGP or S/MIME: opens the keyring, fetches
 * the raw source, decrypts it in this browser (crypto/message.js) and puts the result
 * where the server would have put a body — the same sandboxed frame, built from
 * the same document the server renders for every message.
 *
 * Only when the message comes into view. A thread renders every message's body,
 * collapsed or not, and decrypting forty of them on load is forty raw fetches
 * nobody asked for.
 *
 * A shut keyring is asked open here (Keyring.requireOpen(), the shared unlock
 * dialog) — this is the moment a session the password form did not open finds
 * out it needs the password or the passphrase. Cancelled, the message says it
 * is locked and offers to ask again; opened anywhere else on the page in the
 * meantime (crypto:keyring), it opens by itself.
 *
 * Values:
 *   rawUrl     — the message's byte-exact source (MessageSourceController::raw)
 *   senderKeysUrl — the keys the person holds for the sender
 *                (ContactKeyController::sender), for the signature inside
 *   smimeSignerUrl — for S/MIME: where to ask what the signature inside
 *                means (ContactKeyController::smimeSigner)
 *   document   — the frame document, with `<!--plmail:body-->` where the body goes
 *   keyring    — the keyring's config: stateUrl, unlockUrl, preloginUrl, csrf, userId, email
 *   settingsUrl — Settings → Encryption, for a keyring not set up yet
 *   messages   — translated: decrypting, locked, noKey, noCertificate,
 *                unsupported, noSource, failed,
 *                signature.{valid, invalid, unknown_key, untrusted, key_changed, unchecked}
 *
 * Targets:
 *   status       — what is happening, or what went wrong
 *   signature    — the line in the banner that says what the signature said
 *   signatureActions — the banner's lookup button and settings link, shown
 *                  for a signature plMail cannot vouch for
 *   frameHost    — the mail--message-frame wrapper, hidden until there is HTML
 *   frame        — its iframe
 *   text         — a <pre> for a plain-text message
 *   attachments  — where the decrypted attachments are listed
 */
export default class extends Controller {
    static targets = ["subject", "status", "signature", "signatureActions", "frameHost", "frame", "text", "attachments"];

    static values = {
        rawUrl: String,
        senderKeysUrl: String,
        smimeSignerUrl: { type: String, default: "" },
        document: String,
        keyring: Object,
        settingsUrl: String,
        messages: Object,
        // Set when the account keeps decrypted mail on the server: where the
        // decrypted message goes once it has been opened here. See
        // App\Controller\Mail\DecryptedMailController::upload().
        uploadUrl: { type: String, default: "" },
        outerSubject: { type: String, default: "" },
    };

    #observer = null;
    #opened = false;
    #locked = false;
    #urls = [];
    #onKeyring = null;

    connect() {
        this.#onKeyring = (event) => {
            if (true === this.#locked && "open" === event.detail?.status) {
                this.#opened = false;
                this.#open();
            }
        };
        window.addEventListener("crypto:keyring", this.#onKeyring);

        this.#observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                this.#observer.disconnect();
                this.#open();
            }
        });

        this.#observer.observe(this.element);
    }

    /**
     * The banner's "look up the key" for a signature inside the encryption:
     * asks the server (ContactKeyController::messageLookup) as JSON, and on
     * an answer opens the message again, so the signature is checked against
     * the keys as they now stand. The decrypted result is not kept, so
     * opening again is the honest way to get there.
     */
    async lookUp(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const button = form.querySelector("button");

        button.disabled = true;

        const response = await fetch(form.action, {
            method: "POST",
            headers: { Accept: "application/json" },
            body: new FormData(form),
        }).catch(() => null);

        button.disabled = false;

        const answer = null === response ? null : await response.json().catch(() => null);

        if (null !== answer && true === answer.ok) {
            this.#opened = false;
            this.#urls.forEach((url) => URL.revokeObjectURL(url));
            this.#urls = [];
            await this.#open();
        }
    }

    disconnect() {
        window.removeEventListener("crypto:keyring", this.#onKeyring);
        this.#observer?.disconnect();
        this.#urls.forEach((url) => URL.revokeObjectURL(url));
        this.#urls = [];
    }

    // ── Private ─────────────────────────────────────────────────────────────

    async #open() {
        if (true === this.#opened) {
            return;
        }

        this.#opened = true;
        this.#locked = false;
        this.#say(this.messagesValue.decrypting, false);

        const ring = keyring(this.keyringValue);
        const { status } = await ring.requireOpen();

        if ("open" !== status) {
            this.#locked = true;

            // Shut because the person said no: one press asks again. Shut
            // because there is nothing to open yet, or nothing that could:
            // Settings → Encryption is where that is put right.
            if ("locked" === status) {
                this.#say(this.messagesValue.locked, true, null, () => {
                    this.#opened = false;
                    this.#open();
                });
            } else {
                this.#say(this.messagesValue.locked, true, this.settingsUrlValue);
            }

            return;
        }

        const response = await fetch(this.rawUrlValue, { cache: "no-store" }).catch(() => null);

        if (null === response || false === response.ok) {
            this.#say(this.messagesValue.noSource, true);

            return;
        }

        let opened;

        try {
            opened = await openEncrypted(new Uint8Array(await response.arrayBuffer()), ring, await this.#senderKeys(), {
                smimeSignerUrl: this.smimeSignerUrlValue,
            });
        } catch (error) {
            this.#say({
                no_key: this.messagesValue.noKey,
                // S/MIME, and none of the person's certificates opens it: the
                // note the page shows when they have none at all.
                no_certificate: this.messagesValue.noCertificate,
                unsupported_cipher: this.messagesValue.unsupported,
            }[error?.message] ?? this.messagesValue.failed, true);

            return;
        }

        this.statusTarget.hidden = true;
        this.#keep(opened.mime);
        this.#showSubject(opened.subject);
        this.#showSignature(opened.signature);
        this.#showAttachments(opened.attachments);

        if (null !== opened.html) {
            this.frameHostTarget.hidden = false;
            this.frameTarget.srcdoc = this.documentValue.replace("<!--plmail:body-->", () => opened.html);
        } else {
            this.textTarget.textContent = opened.text ?? "";
            this.textTarget.hidden = false;
        }
    }

    /**
     * The sender's keys, or none when they cannot be fetched — the signature
     * is then checked against the person's own keys alone, which only ever
     * says less, never something false.
     */
    async #senderKeys() {
        if (false === this.hasSenderKeysUrlValue || "" === this.senderKeysUrlValue) {
            return [];
        }

        const response = await fetch(this.senderKeysUrlValue, { headers: { Accept: "application/json" }, cache: "no-store" }).catch(() => null);

        return null === response || false === response.ok ? [] : response.json().catch(() => []);
    }

    /**
     * The subject the sender encrypted (RFC 9788), when the outside said
     * something else — "..." for Thunderbird and plMail. Said in the banner,
     * and put in the conversation's heading where that heading is only the
     * placeholder.
     */
    #showSubject(subject) {
        if (null === subject || undefined === subject || subject === this.outerSubjectValue) {
            return;
        }

        if (true === this.hasSubjectTarget) {
            this.subjectTarget.textContent = this.messagesValue.subject.replace("%subject%", subject);
            this.subjectTarget.hidden = false;
        }

        const heading = document.querySelector("[data-thread-subject]");

        if (null !== heading && true === isPlaceholder(heading.textContent)) {
            heading.textContent = subject;
        }
    }

    /**
     * Hand the decrypted message to the server, for an account that keeps
     * decrypted mail. Best effort and out of the way: the background
     * decrypter (crypto--decrypted-mail) picks up anything this misses.
     */
    #keep(mime) {
        if ("" === this.uploadUrlValue || undefined === mime) {
            return;
        }

        fetch(this.uploadUrlValue, {
            method: "POST",
            body: mime,
            headers: { "Content-Type": "application/octet-stream", "X-CSRF-Token": csrfToken(), "X-Requested-With": "XMLHttpRequest" },
        }).catch(() => undefined);
    }

    #say(message, isProblem, link = null, retry = null) {
        this.statusTarget.replaceChildren(document.createTextNode(message));
        this.statusTarget.className = `text-sm ${isProblem ? "text-danger" : "text-ink-muted"}`;
        this.statusTarget.hidden = false;

        if (null !== link) {
            const anchor = document.createElement("a");

            anchor.href = link;
            anchor.className = "ml-1 font-medium text-link hover:underline";
            anchor.textContent = this.messagesValue.openSettings;
            this.statusTarget.append(anchor);
        }

        if (null !== retry) {
            const button = document.createElement("button");

            button.type = "button";
            button.className = "ml-1 font-medium text-link hover:underline cursor-pointer";
            button.textContent = this.messagesValue.openSettings;
            button.addEventListener("click", retry, { once: true });
            this.statusTarget.append(button);
        }
    }

    #showSignature(signature) {
        if (false === this.hasSignatureTarget || null === signature) {
            return;
        }

        const text = this.messagesValue.signature?.[signature.state] ?? "";

        this.signatureTarget.textContent = text.replace("%signer%", formatSigner(signature.signer));
        this.signatureTarget.hidden = "" === text;

        if (true === this.hasSignatureActionsTarget) {
            this.signatureActionsTarget.hidden = false === ["unknown_key", "untrusted", "key_changed"].includes(signature.state);
        }
    }

    /**
     * The decrypted attachments, as downloads. blob: links with a `download`
     * attribute: the bytes never leave the browser, and Turbo leaves a link
     * that downloads alone.
     */
    #showAttachments(attachments) {
        if (0 === attachments.length) {
            return;
        }

        this.attachmentsTarget.replaceChildren(...attachments.map((part) => {
            const url = URL.createObjectURL(new Blob([part.content], { type: "application/octet-stream" }));
            const link = document.createElement("a");
            const icon = document.createElement("i");

            this.#urls.push(url);
            link.href = url;
            link.download = part.filename ?? "attachment";
            link.className = "inline-flex items-center gap-1.5 rounded-lg border border-line bg-raised px-2.5 py-1.5 text-xs text-ink hover:bg-hover";
            icon.className = "fa-solid fa-paperclip text-ink-faint text-[11px]";
            icon.setAttribute("aria-hidden", "true");
            link.append(icon, document.createTextNode(` ${part.filename ?? "attachment"}`));

            return link;
        }));
        this.attachmentsTarget.hidden = false;
    }
}

function formatSigner(signer) {
    return null === signer ? "" : String(signer).toUpperCase().match(/.{1,4}/g).join(" ");
}
