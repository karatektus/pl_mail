import { Controller } from "@hotwired/stimulus";
import { keyring } from "../../crypto/keyring.js";
import { openEncrypted } from "../../crypto/message.js";

/**
 * Shows an OpenPGP-encrypted message: opens the keyring, fetches the raw
 * source, decrypts it in this browser (crypto/message.js) and puts the result
 * where the server would have put a body — the same sandboxed frame, built from
 * the same document the server renders for every message.
 *
 * Only when the message comes into view. A thread renders every message's body,
 * collapsed or not, and decrypting forty of them on load is forty raw fetches
 * nobody asked for.
 *
 * Values:
 *   rawUrl     — the message's byte-exact source (MessageSourceController::raw)
 *   document   — the frame document, with `<!--plmail:body-->` where the body goes
 *   keyring    — the keyring's config: stateUrl, unlockUrl, preloginUrl, csrf, userId, email
 *   settingsUrl — Settings → Encryption, for a locked keyring
 *   messages   — translated: decrypting, locked, noKey, noSource, failed,
 *                signature.{valid, invalid, unknown_key, unchecked}
 *
 * Targets:
 *   status       — what is happening, or what went wrong
 *   signature    — the line in the banner that says what the signature said
 *   frameHost    — the mail--message-frame wrapper, hidden until there is HTML
 *   frame        — its iframe
 *   text         — a <pre> for a plain-text message
 *   attachments  — where the decrypted attachments are listed
 */
export default class extends Controller {
    static targets = ["status", "signature", "frameHost", "frame", "text", "attachments"];

    static values = {
        rawUrl: String,
        document: String,
        keyring: Object,
        settingsUrl: String,
        messages: Object,
    };

    #observer = null;
    #opened = false;
    #urls = [];

    connect() {
        this.#observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                this.#observer.disconnect();
                this.#open();
            }
        });

        this.#observer.observe(this.element);
    }

    disconnect() {
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
        this.#say(this.messagesValue.decrypting, false);

        const ring = keyring(this.keyringValue);
        let status;

        try {
            status = (await ring.ensure()).status;
        } catch {
            status = "broken";
        }

        if ("open" !== status) {
            this.#say(this.messagesValue.locked, true, this.settingsUrlValue);

            return;
        }

        const response = await fetch(this.rawUrlValue, { cache: "no-store" }).catch(() => null);

        if (null === response || false === response.ok) {
            this.#say(this.messagesValue.noSource, true);

            return;
        }

        let opened;

        try {
            opened = await openEncrypted(new Uint8Array(await response.arrayBuffer()), ring);
        } catch (error) {
            this.#say("no_key" === error?.message ? this.messagesValue.noKey : this.messagesValue.failed, true);

            return;
        }

        this.statusTarget.hidden = true;
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

    #say(message, isProblem, link = null) {
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
    }

    #showSignature(signature) {
        if (false === this.hasSignatureTarget || null === signature) {
            return;
        }

        const text = this.messagesValue.signature?.[signature.state] ?? "";

        this.signatureTarget.textContent = text.replace("%signer%", formatSigner(signature.signer));
        this.signatureTarget.hidden = "" === text;
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
