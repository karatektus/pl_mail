/**
 * Opens an encrypted message in the browser, from its raw source: OpenPGP or
 * S/MIME.
 *
 * The server keeps no body for an encrypted message (see
 * App\Service\Crypto\MessageCryptoDetector); the raw source is all there is,
 * and only the keys this browser has opened can read it. What comes back is
 * what the page needs to show it: sanitized HTML for the reading frame, or the
 * plain text, the attachments, and what the signature inside said.
 *
 * Three shapes, one way out. PGP/MIME (RFC 3156): the ciphertext is a part of
 * a multipart/encrypted message, and what it decrypts to is a whole MIME
 * entity of its own — text, HTML, images, attachments — which is parsed like
 * any mail. Inline PGP: the armored block sits in a text body, and decrypts
 * to text. S/MIME (RFC 8551): the message is a CMS EnvelopedData
 * (`smime.p7m`), opened with the person's own certificates
 * (crypto/smime.js), and what it decrypts to is again a MIME entity —
 * usually signed inside, as multipart/signed or as opaque signed data. From
 * the decrypted entity on, every scheme goes through present(): the same
 * parser, the same sanitizer, the same attachments.
 *
 * The HTML is sanitized here, with DOMPurify, to the same end MailBodySanitizer
 * serves on the server: nothing that runs, no forms, no frames, and every link
 * opening away from the app. Remote images are blocked outright and cannot be
 * shown — loading them through the image proxy would hand the server the very
 * URLs the sender encrypted. Embedded (cid:) images are shown, as data: URIs,
 * which is all the frame's own policy allows.
 */

import { csrfToken } from "../csrf.js";

const decoder = new TextDecoder();
const ARMOR = /-----BEGIN PGP MESSAGE-----[\s\S]*?-----END PGP MESSAGE-----/;
const PKCS7_MIME = ["application/pkcs7-mime", "application/x-pkcs7-mime"];
const PKCS7_SIGNATURE = ["application/pkcs7-signature", "application/x-pkcs7-signature"];

/** A 1×1 transparent GIF: what a blocked remote image is drawn as. */
const BLANK = "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";

/**
 * @param {Uint8Array} raw  the message as it arrived
 * @param {import("./keyring.js").Keyring} ring  an open keyring
 * @param {{fingerprint: string, publicKey: string, status: string, pinned: boolean}[]} contactKeys
 *        the keys the person holds for the sender (ContactKeyController::sender)
 * @param {{smimeSignerUrl?: ?string, sender?: string}} [options]
 *        where to ask what an S/MIME signature inside means
 *        (ContactKeyController::smimeSigner); without it, a signature by
 *        anybody but the person is only "untrusted". And the From address,
 *        to tell the sender's own attached key from anybody else's.
 * @returns {Promise<{html: ?string, text: ?string, attachments: object[], signature: ?{state: string, signer: ?string}, subject: ?string, mime: Uint8Array, senderKey: ?{fingerprint: string, armored: string, state: string}}>}
 * @throws {Error} no_ciphertext, no_key, no_certificate (S/MIME, and no
 *         certificate of the person's opens it), unsupported_cipher
 */
export async function openEncrypted(raw, ring, contactKeys = [], { smimeSignerUrl = null, sender = "" } = {}) {
    const { default: PostalMime } = await import("postal-mime");
    const outer = await PostalMime.parse(raw);
    const enveloped = findEnveloped(outer);
    const known = { sender: String(sender || outer.from?.address || "").toLowerCase(), contactKeys, own: ring.ownFingerprints() };

    if (null !== enveloped) {
        return openSmime(enveloped, ring, smimeSignerUrl, known);
    }

    return openPgp(outer, ring, contactKeys, known);
}

async function openPgp(outer, ring, contactKeys, known) {
    const openpgp = await import("openpgp");
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

        return { html: null, text, attachments: [], signature, subject: null, mime: inlineEntity(text), senderKey: null };
    }

    return present(decrypted.data, signature, isPgpSignaturePart, known);
}

/**
 * S/MIME: the envelope opened with whichever of the person's certificates it
 * was encrypted to, and the signature inside checked.
 */
async function openSmime(enveloped, ring, smimeSignerUrl, known) {
    const smime = await import("./smime.js");
    const keys = await ring.smimeKeys();

    if (0 === keys.length) {
        throw new Error("no_certificate");
    }

    let content;

    try {
        content = await smime.openEnvelope(enveloped, keys);
    } catch (error) {
        throw new Error("unsupported_cipher" === error?.message ? "unsupported_cipher" : "no_certificate");
    }

    const { entity, signature } = await smimeSignature(smime, content, ring, smimeSignerUrl);

    return present(entity, signature, isSmimeSignaturePart, known);
}

/**
 * The decrypted entity, parsed and made fit to show. The same for every
 * scheme; only what counts as a signature part differs.
 */
async function present(entity, signature, isSignaturePart, known) {
    const { default: PostalMime } = await import("postal-mime");
    const inner = await PostalMime.parse(entity);
    const parts = (inner.attachments ?? []).filter((part) => false === isSignaturePart(part));
    const senderKey = await senderKeyOf(parts, known);
    const attachments = parts.filter((part) => part !== senderKey?.part);
    const html = null === (inner.html ?? null) ? null : await sanitize(inner.html, attachments);

    return {
        html,
        text: null === html ? (inner.text ?? "") : null,
        attachments: attachments.filter((part) => false === (null !== html && isEmbedded(part, inner.html))),
        // The sender's own public key, attached inside the encryption: said
        // in the banner rather than listed, as the server does for mail it
        // can read (MessagePart::$senderKeyFingerprint, phase 6g of #28).
        senderKey: null === senderKey ? null : { fingerprint: senderKey.fingerprint, armored: senderKey.armored, state: senderKey.state },
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
        mime: entity,
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
 * The EnvelopedData of an S/MIME message: the whole message as
 * application/pkcs7-mime, which postal-mime hands over as its one attachment,
 * or an `smime.p7m` attachment the way Exchange sometimes delivers it. Signed
 * data (`smime-type=signed-data`) is not encrypted and is not taken.
 *
 * @returns {?Uint8Array}
 */
function findEnveloped(outer) {
    for (const part of outer.attachments ?? []) {
        const type = String(part.mimeType ?? "").toLowerCase();
        const name = String(part.filename ?? "").toLowerCase();

        if ((PKCS7_MIME.includes(type) || "smime.p7m" === name) && false === /signed-data/i.test(String(part.contentType ?? ""))) {
            return new Uint8Array(part.content);
        }
    }

    return null;
}

/**
 * What an S/MIME message decrypted to, and what the signature inside it said:
 * multipart/signed with a detached signature (what Thunderbird, Outlook and
 * plMail send), opaque signed data, or nothing signed at all. The entity
 * handed on is the signed content — which is where RFC 9788 puts the
 * protected subject — not the signature's wrapping.
 *
 * @returns {Promise<{entity: Uint8Array, signature: ?{state: string, signer: ?string}}>}
 */
async function smimeSignature(smime, content, ring, smimeSignerUrl) {
    const text = binaryString(content);
    const [headers] = splitHead(text);
    const contentType = unfold(headers).match(/^content-type:\s*(.+)$/im)?.[1] ?? "";
    let checked = null;
    let entity = content;

    try {
        if (/^\s*multipart\/signed/i.test(contentType) && /pkcs7-signature/i.test(contentType)) {
            const split = splitSigned(text, contentType);

            if (null !== split) {
                checked = await smime.verify(split.signature, split.signed);
                entity = split.signed;
            }
        } else if (PKCS7_MIME.some((type) => contentType.toLowerCase().startsWith(type)) && /signed-data/i.test(contentType)) {
            checked = await smime.verify(base64Body(text));
            entity = checked.content ?? content;
        }
    } catch {
        return { entity, signature: { state: "invalid", signer: null } };
    }

    if (null === checked) {
        return { entity, signature: null };
    }

    if (false === checked.intact || null === checked.certificate) {
        return { entity, signature: { state: "invalid", signer: null } };
    }

    return { entity, signature: await smimeSignerState(smime, checked, ring, smimeSignerUrl) };
}

/**
 * Whom an intact S/MIME signature is by, and how far to believe it: the
 * person's own certificate is valid here and now; anybody else's the server
 * judges, since it holds the system's authorities and the contacts'
 * certificates (ContactKeyController::smimeSigner).
 */
async function smimeSignerState(smime, checked, ring, smimeSignerUrl) {
    const { fingerprint } = await smime.describeCertificate(checked.certificate);

    if (ring.ownSmimeFingerprints().includes(fingerprint)) {
        return { state: "valid", signer: fingerprint };
    }

    if (null === smimeSignerUrl || "" === smimeSignerUrl) {
        return { state: "untrusted", signer: fingerprint };
    }

    const response = await fetch(smimeSignerUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": csrfToken(), "X-Requested-With": "XMLHttpRequest" },
        body: JSON.stringify({ certificate: checked.certificate, chain: checked.chain }),
    }).catch(() => null);
    const answer = null === response || false === response.ok ? null : await response.json().catch(() => null);

    return { state: answer?.state ?? "untrusted", signer: answer?.signer ?? fingerprint };
}

/**
 * The two halves of a multipart/signed entity, byte for byte: the signed
 * part as it stands between the boundaries — the CRLF before a boundary
 * belongs to the boundary — and the signature, decoded.
 *
 * @returns {?{signed: Uint8Array, signature: Uint8Array}}
 */
function splitSigned(text, contentType) {
    const marker = /boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i.exec(contentType);

    if (null === marker) {
        return null;
    }

    const delimiter = `--${marker[1] ?? marker[2]}`;
    const pieces = text.split(new RegExp(`\r?\n?${escapeRegExp(delimiter)}(?:--)?[ \t]*\r?\n`));

    if (pieces.length < 3) {
        return null;
    }

    // Cut at the closing delimiter by hand: when nothing follows it, the
    // split above has no line end to find there.
    return { signed: bytesOf(pieces[1]), signature: base64Body(pieces[2].split(delimiter)[0]) };
}

/** The decoded body of an entity whose body is base64. */
function base64Body(text) {
    const [, body] = splitHead(text);
    const clean = body.replace(/[^A-Za-z0-9+/=]/g, "");

    return Uint8Array.from(atob(clean), (character) => character.charCodeAt(0));
}

function splitHead(text) {
    const match = /\r?\n\r?\n/.exec(text);

    return null === match ? [text, ""] : [text.slice(0, match.index), text.slice(match.index + match[0].length)];
}

function unfold(headers) {
    return headers.replace(/\r?\n[ \t]+/g, " ");
}

/** Bytes as a string of the same length, one character per byte. */
function binaryString(bytes) {
    let text = "";

    for (let index = 0; index < bytes.length; index += 0x8000) {
        text += String.fromCharCode(...bytes.subarray(index, index + 0x8000));
    }

    return text;
}

function bytesOf(text) {
    return Uint8Array.from(text, (character) => character.charCodeAt(0));
}

function escapeRegExp(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
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

/**
 * The attached part that is the sender's own public key, by the rule the
 * server uses (ContactKeyCollector::markAttachedKeys()): a key with a user ID
 * for the From address, or one plMail already holds for it — a contact key
 * for that address, or one of the person's own keys on their own mail.
 * Somebody else's key stays an attachment. `state` is where it stands with
 * plMail: own, current, changed, or none (not held).
 */
async function senderKeyOf(parts, { sender, contactKeys, own }) {
    const candidates = parts.filter(looksLikeKey);

    if (0 === candidates.length) {
        return null;
    }

    const openpgp = await import("openpgp");

    for (const part of candidates) {
        let found = [];

        try {
            found = await openpgp.readKeys({ armoredKeys: decoder.decode(part.content) });
        } catch {
            try {
                found = await openpgp.readKeys({ binaryKeys: new Uint8Array(part.content) });
            } catch {
                continue;
            }
        }

        for (const key of found) {
            const fingerprint = key.getFingerprint().toLowerCase();
            const emails = key.users.map((user) => String(user.userID?.email ?? "").toLowerCase());
            const held = contactKeys.find((candidate) => String(candidate.fingerprint).toLowerCase() === fingerprint);

            if (true === own.includes(fingerprint) || undefined !== held || ("" !== sender && emails.includes(sender))) {
                const state = own.includes(fingerprint) ? "own" : (undefined === held ? "none" : ("current" === held.status ? "current" : "changed"));

                return { part, fingerprint, armored: key.armor(), state };
            }
        }
    }

    return null;
}

/** As ContactKeyCollector::looksLikeKey(): a key file, not a signature or the ciphertext. */
function looksLikeKey(part) {
    const type = String(part.mimeType ?? "").toLowerCase();
    const name = String(part.filename ?? "").toLowerCase();

    if ("application/pgp-signature" === type || "application/pgp-encrypted" === type || "signature.asc" === name || "encrypted.asc" === name) {
        return false;
    }

    return "application/pgp-keys" === type || name.endsWith(".asc") || name.endsWith(".key") || name.endsWith(".pub");
}

function isPgpSignaturePart(part) {
    return "application/pgp-signature" === String(part.mimeType).toLowerCase();
}

function isSmimeSignaturePart(part) {
    return PKCS7_SIGNATURE.includes(String(part.mimeType).toLowerCase()) || "smime.p7s" === String(part.filename ?? "").toLowerCase();
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
