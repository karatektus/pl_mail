/**
 * Opens an OpenPGP-encrypted message in the browser, from its raw source.
 *
 * The server keeps no body for an encrypted message (see
 * App\Service\Crypto\MessageCryptoDetector); the raw source is all there is,
 * and only the keys this browser has opened can read it. What comes back is
 * what the page needs to show it: sanitized HTML for the reading frame, or the
 * plain text, the attachments, and what the signature inside said.
 *
 * Two shapes. PGP/MIME (RFC 3156): the ciphertext is a part of a
 * multipart/encrypted message, and what it decrypts to is a whole MIME entity
 * of its own — text, HTML, images, attachments — which is parsed like any
 * mail. Inline PGP: the armored block sits in a text body, and decrypts to
 * text.
 *
 * The HTML is sanitized here, with DOMPurify, to the same end MailBodySanitizer
 * serves on the server: nothing that runs, no forms, no frames, and every link
 * opening away from the app. Remote images are blocked outright and cannot be
 * shown — loading them through the image proxy would hand the server the very
 * URLs the sender encrypted. Embedded (cid:) images are shown, as data: URIs,
 * which is all the frame's own policy allows.
 */

const decoder = new TextDecoder();
const ARMOR = /-----BEGIN PGP MESSAGE-----[\s\S]*?-----END PGP MESSAGE-----/;

/** A 1×1 transparent GIF: what a blocked remote image is drawn as. */
const BLANK = "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";

/**
 * @param {Uint8Array} raw  the message as it arrived
 * @param {import("./keyring.js").Keyring} ring  an open keyring
 * @param {{fingerprint: string, publicKey: string, status: string, pinned: boolean}[]} contactKeys
 *        the keys the person holds for the sender (ContactKeyController::sender)
 * @returns {Promise<{html: ?string, text: ?string, attachments: object[], signature: ?{state: string, signer: ?string}}>}
 */
export async function openEncrypted(raw, ring, contactKeys = []) {
    const [openpgp, { default: PostalMime }] = await Promise.all([import("openpgp"), import("postal-mime")]);

    const outer = await PostalMime.parse(raw);
    const [armored, inline] = findCiphertext(outer);

    if (null === armored) {
        throw new Error("no_ciphertext");
    }

    let decrypted;
    const verificationKeys = await ring.verificationKeys(contactKeys);

    try {
        decrypted = await openpgp.decrypt({
            message: await openpgp.readMessage({ armoredMessage: armored }),
            decryptionKeys: await ring.decryptionKeys(),
            verificationKeys,
            format: "binary",
        });
    } catch {
        throw new Error("no_key");
    }

    const signature = await signatureOf(decrypted.signatures, verificationKeys, ring.ownFingerprints(), contactKeys);

    if (true === inline) {
        const text = decoder.decode(decrypted.data);

        return { html: null, text, attachments: [], signature, subject: null, mime: inlineEntity(text) };
    }

    const inner = await PostalMime.parse(decrypted.data);
    const attachments = (inner.attachments ?? []).filter((part) => false === isSignaturePart(part));
    const html = null === (inner.html ?? null) ? null : await sanitize(inner.html, attachments);

    return {
        html,
        text: null === html ? (inner.text ?? "") : null,
        attachments: attachments.filter((part) => false === (null !== html && isEmbedded(part, inner.html))),
        // A message signed inside the encryption the RFC 3156 way — a
        // multipart/signed entity — carries its signature as a part rather
        // than as an OpenPGP packet. Said as unchecked for now rather than
        // checked badly: the bytes it covers are not what postal-mime returns.
        signature: signature ?? ((inner.attachments ?? []).some(isSignaturePart) ? { state: "unchecked", signer: null } : null),
        // The subject the sender put inside the encryption (RFC 9788 header
        // protection), when they did; the outside then says only "...".
        subject: "string" === typeof inner.subject && "" !== inner.subject.trim() ? inner.subject.trim() : null,
        // The decrypted inner message, whole: what an account that keeps
        // decrypted mail on the server is handed (App\Service\Crypto\DecryptedMail).
        mime: decrypted.data,
    };
}

/**
 * Inline PGP decrypts to text, not to a message; for the server it is wrapped
 * in the smallest message that says what it is.
 */
function inlineEntity(text) {
    return new TextEncoder().encode(`Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n${text.replace(/\r\n|\r|\n/g, "\r\n")}`);
}

/**
 * Whether a subject is only the placeholder a sender puts outside when the
 * real one is encrypted (RFC 9788 header protection): "..." from Thunderbird
 * and plMail, "[...]" from the RFC, "Encrypted Message" from some others.
 */
export function isPlaceholder(subject) {
    return /^\s*(\.\.\.|\[\.\.\.\]|encrypted message)\s*$/i.test(String(subject ?? ""));
}

// ── Private ─────────────────────────────────────────────────────────────────

/**
 * The armored ciphertext, and whether it came inline.
 *
 * @returns {[?string, boolean]}
 */
function findCiphertext(outer) {
    for (const part of outer.attachments ?? []) {
        const text = decoder.decode(part.content);
        const match = ARMOR.exec(text);

        if (null !== match) {
            return [match[0], false];
        }
    }

    const match = ARMOR.exec(outer.text ?? "") ?? ARMOR.exec(stripTags(outer.html ?? ""));

    return null === match ? [null, false] : [match[0], true];
}

/**
 * What the first signature in the message said, by the rules the server
 * applies to signed mail (App\Service\Crypto\SignatureVerifier): the person's
 * own key or a pinned contact key is valid, the sender's unpinned key is
 * untrusted, and any other key of theirs — a changed one, or one plMail does
 * not have while it knows another — is key_changed.
 */
async function signatureOf(signatures, keys, own, contactKeys) {
    const first = (signatures ?? [])[0];

    if (undefined === first) {
        return null;
    }

    const keyID = first.keyID?.toHex?.() ?? null;
    const known = contactKeys.find((key) => "current" === key.status) ?? null;

    try {
        await first.verified;
    } catch (error) {
        if (false === /could not find signing key/i.test(String(error?.message))) {
            return { state: "invalid", signer: keyID };
        }

        return { state: null === known ? "unknown_key" : "key_changed", signer: keyID };
    }

    // Which key signed, by its primary fingerprint: a key usually signs with
    // a subkey, and the fingerprint is what a person compares.
    const signerKey = keys.find((key) => key.getKeyIDs().some((id) => id.toHex() === keyID)) ?? null;
    const fingerprint = signerKey?.getFingerprint().toLowerCase() ?? null;
    const signer = fingerprint ?? keyID;

    if (null !== fingerprint && own.includes(fingerprint)) {
        return { state: "valid", signer };
    }

    if (null !== known && fingerprint === known.fingerprint) {
        return { state: true === known.pinned ? "valid" : "untrusted", signer };
    }

    return { state: "key_changed", signer };
}

function isSignaturePart(part) {
    return "application/pgp-signature" === String(part.mimeType).toLowerCase();
}

function isEmbedded(part, html) {
    const id = contentId(part);

    return null !== id && String(html).includes(`cid:${id}`);
}

function contentId(part) {
    const id = String(part.contentId ?? "").replace(/^<|>$/g, "");

    return "" === id ? null : id;
}

/**
 * DOMPurify, set up for a mail body in a sandboxed frame.
 *
 * A fresh instance per message, because hooks are per instance and these ones
 * close over this message's attachments.
 */
async function sanitize(html, attachments) {
    const { default: DOMPurify } = await import("dompurify");
    const purify = DOMPurify(window);
    const embedded = new Map(attachments.filter((part) => null !== contentId(part)).map((part) => [contentId(part), part]));

    purify.addHook("afterSanitizeAttributes", (node) => {
        if ("A" === node.nodeName && node.hasAttribute("href")) {
            node.setAttribute("target", "_blank");
            node.setAttribute("rel", "noopener noreferrer");
        }

        if ("IMG" === node.nodeName) {
            const src = node.getAttribute("src") ?? "";

            if (src.toLowerCase().startsWith("cid:")) {
                const part = embedded.get(src.slice(4));

                node.setAttribute("src", undefined === part ? BLANK : dataUri(part));
            } else if (true === isRemote(src)) {
                node.setAttribute("src", BLANK);
                node.setAttribute("data-plmail-blocked", "1");
                node.removeAttribute("srcset");
            }
        }

        if (node.hasAttribute?.("style")) {
            node.setAttribute("style", node.getAttribute("style").replace(/url\(\s*(['"]?)(?:https?:)?\/\/[^)]*\1\s*\)/gi, "none"));
        }

        if (node.hasAttribute?.("background") && true === isRemote(node.getAttribute("background"))) {
            node.removeAttribute("background");
        }
    });

    return purify.sanitize(html, {
        FORBID_TAGS: ["script", "iframe", "frame", "object", "embed", "form", "input", "button", "base", "meta", "link"],
        FORBID_ATTR: ["srcset"],
        ALLOW_DATA_ATTR: false,
        ADD_ATTR: ["target"],
        // Keeps a leading <style>: the frame shows it, and its own policy
        // stops a stylesheet from fetching anything remote.
        FORCE_BODY: true,
    });
}

function isRemote(url) {
    return /^\s*(https?:)?\/\//i.test(String(url));
}

function dataUri(part) {
    const bytes = new Uint8Array(part.content);
    let binary = "";

    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    }

    return `data:${part.mimeType};base64,${btoa(binary)}`;
}

function stripTags(html) {
    return new DOMParser().parseFromString(html, "text/html").body.textContent ?? "";
}
