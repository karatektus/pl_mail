/**
 * Builds a MIME entity in the browser: the body of a message the server will
 * send without being able to read it, or send exactly as it was signed.
 *
 * The inside of a message only — no From, no To, no Subject. Those are the
 * outer headers, which the server writes when the message leaves
 * (App\Service\Mail\Mime\SealedEntityPart); what is built here is everything
 * an OpenPGP signature or encryption has to cover.
 *
 * Written for that purpose and nothing else, which keeps it small:
 *
 *   - CRLF line ends everywhere and no line longer than 76 characters, so the
 *     bytes survive any mail server unchanged — a signature over them stays
 *     valid, and there is nothing a transport would want to re-encode;
 *   - every part base64, text included: quoted-printable would be kinder to
 *     a reader of the raw source, but base64 has no trailing whitespace and no
 *     soft line breaks for anything along the way to "fix";
 *   - the shape mail programs expect: multipart/alternative for text and
 *     HTML, multipart/related for images inside the HTML, multipart/mixed
 *     around that when there are attachments.
 */

const CRLF = "\r\n";
const encoder = new TextEncoder();

/**
 * @param {object} message
 * @param {?string} message.html           the HTML body, or null for plain text
 * @param {string}  message.text           the text body
 * @param {Array<{filename: string, contentType: string, bytes: Uint8Array}>} [message.attachments]
 * @param {Array<{contentId: string, contentType: string, bytes: Uint8Array}>} [message.related]
 *        images the HTML references as `cid:` (see extractInlineImages())
 * @param {?Object<string, string>} [message.protectedHeaders]
 *        header fields to carry inside the entity (RFC 9788 header protection):
 *        name → value, encoded here. The entity is then marked
 *        `protected-headers="v1"`, which is what Thunderbird and others read.
 * @returns {string} the entity: its headers, a blank line, its body
 */
export function buildEntity({ html, text, attachments = [], related = [], protectedHeaders = null }) {
    const entity = buildBody({ html, text, attachments, related });

    if (null === protectedHeaders) {
        return entity;
    }

    const [first, ...rest] = entity.split(CRLF);
    const fields = Object.entries(protectedHeaders)
        .filter(([, value]) => "" !== String(value ?? "").trim())
        .map(([name, value]) => `${name}: ${encodeHeader(value)}`);

    return [...fields, `${first}; protected-headers="v1"`, ...rest].join(CRLF);
}

/**
 * A header value as it may stand in a message: as it is when it is plain
 * printable ASCII, otherwise as RFC 2047 encoded-words of at most 75
 * characters each, folded onto continuation lines.
 */
export function encodeHeader(value) {
    const text = String(value).replace(/[\r\n]+/g, " ").trim();

    if (/^[\x20-\x7e]*$/.test(text)) {
        return text;
    }

    const words = [];
    let chunk = "";

    for (const character of text) {
        if (encoder.encode(chunk + character).length > 45) {
            words.push(chunk);
            chunk = "";
        }

        chunk += character;
    }

    words.push(chunk);

    return words.map((word) => `=?UTF-8?B?${bytesToBase64(encoder.encode(word))}?=`).join(`${CRLF} `);
}

function buildBody({ html, text, attachments, related }) {
    const textPart = leaf("text/plain; charset=utf-8", encoder.encode(normalise(text ?? "")));
    let body = textPart;

    if (null !== html && undefined !== html && "" !== html.trim()) {
        let htmlPart = leaf("text/html; charset=utf-8", encoder.encode(normalise(html)));

        if (0 < related.length) {
            htmlPart = multipart("related", [htmlPart, ...related.map((image) => leaf(
                image.contentType,
                image.bytes,
                [`Content-ID: <${image.contentId}>`, "Content-Disposition: inline"],
            ))], 'type="text/html"');
        }

        body = multipart("alternative", [textPart, htmlPart]);
    }

    if (0 === attachments.length) {
        return body;
    }

    return multipart("mixed", [body, ...attachments.map((file) => leaf(
        `${file.contentType || "application/octet-stream"}; ${parameter("name", file.filename)}`,
        file.bytes,
        [`Content-Disposition: attachment; ${parameter("filename", file.filename)}`],
    ))]);
}

/**
 * Takes the images out of an HTML body and gives them back as parts the
 * HTML references by `cid:` — the form a message carries them in.
 *
 * `load` turns an <img src> into bytes and a type, or null for an image that
 * is not the message's own (a remote image is left as it is: it was never
 * part of the message).
 *
 * @param {string} html
 * @param {(src: string) => Promise<?{bytes: Uint8Array, contentType: string}>} load
 * @returns {Promise<{html: string, related: Array<{contentId: string, contentType: string, bytes: Uint8Array}>}>}
 */
export async function extractInlineImages(html, load) {
    const doc = new DOMParser().parseFromString(`<body>${html}</body>`, "text/html");
    const related = [];
    const seen = new Map();

    for (const image of doc.body.querySelectorAll("img[src]")) {
        const src = image.getAttribute("src");

        if (true === seen.has(src)) {
            image.setAttribute("src", `cid:${seen.get(src)}`);
            continue;
        }

        const loaded = await load(src);

        if (null === loaded) {
            continue;
        }

        const contentId = `${randomHex(12)}@plmail`;

        seen.set(src, contentId);
        related.push({ contentId, contentType: loaded.contentType, bytes: loaded.bytes });
        image.setAttribute("src", `cid:${contentId}`);
        image.removeAttribute("data-cid");
    }

    return { html: doc.body.innerHTML, related };
}

/** A data: URI's bytes and type, or null when it is not one. */
export function fromDataUri(uri) {
    const match = /^data:([a-z0-9.+-]+\/[a-z0-9.+-]+)(?:;[^,]*)?;base64,(.*)$/is.exec(String(uri));

    if (null === match) {
        return null;
    }

    return { contentType: match[1].toLowerCase(), bytes: base64ToBytes(match[2]) };
}

export function bytesToBase64(bytes) {
    let binary = "";

    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    }

    return btoa(binary);
}

export function base64ToBytes(base64) {
    const binary = atob(base64.replace(/\s+/g, ""));
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
}

/** Text with every line ending as CRLF, the only line end a message has. */
export function normalise(text) {
    return String(text).replace(/\r\n|\r|\n/g, CRLF);
}

export function boundary() {
    return `----=_plmail_${randomHex(16)}`;
}

// ── Private ─────────────────────────────────────────────────────────────────

function leaf(contentType, bytes, extraHeaders = []) {
    return [
        `Content-Type: ${contentType}`,
        "Content-Transfer-Encoding: base64",
        ...extraHeaders,
        "",
        wrap(bytesToBase64(bytes)),
    ].join(CRLF);
}

function multipart(subtype, parts, parameters = "") {
    const marker = boundary();
    const extra = "" === parameters ? "" : `; ${parameters}`;

    return [
        `Content-Type: multipart/${subtype}; boundary="${marker}"${extra}`,
        "",
        ...parts.flatMap((part) => [`--${marker}`, part]),
        `--${marker}--`,
        "",
    ].join(CRLF);
}

/**
 * A `name` or `filename` parameter. Plain ASCII as a quoted string; anything
 * else as RFC 2231's `name*=utf-8''…`, which every current mail program reads.
 */
function parameter(name, value) {
    const text = String(value || "attachment").replace(/[\r\n]/g, " ");

    if (/^[\x20-\x7e]*$/.test(text)) {
        return `${name}="${text.replace(/["\\]/g, "\\$&")}"`;
    }

    return `${name}*=utf-8''${encodeURIComponent(text).replace(/['()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`)}`;
}

function wrap(base64) {
    return (base64.match(/.{1,76}/g) ?? [""]).join(CRLF);
}

function randomHex(bytes) {
    return Array.from(crypto.getRandomValues(new Uint8Array(bytes)), (b) => b.toString(16).padStart(2, "0")).join("");
}
