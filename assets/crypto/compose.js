/**
 * What the composer seals, and how: the draft, its attachments, and the
 * message that goes out.
 *
 * Three kinds of sealing, for three readers:
 *
 *   - The draft and its attachments are for the person alone. Encrypted to
 *     every active address key they hold, so whichever From they pick later
 *     can still open it; not signed, because nobody else will ever check.
 *
 *   - The outgoing message, encrypted: PGP/MIME (RFC 3156) to every recipient
 *     and to the sender's own key — without that, the Sent copy would be a
 *     message its own author cannot read. Signed inside the encryption with
 *     the key for the From address, the way Proton and Thunderbird do it.
 *     Inline PGP when every recipient asked for it and there is nothing but
 *     text to send.
 *
 *   - The outgoing message, signed only: multipart/signed around the exact
 *     entity, with a detached signature.
 *
 * Each of the last two in OpenPGP or in S/MIME (RFC 8551), with the same
 * shapes: S/MIME signs with a detached CMS signature in multipart/signed,
 * and encrypts that signed entity into an `smime.p7m` (crypto/smime.js) to
 * every recipient's certificate and the sender's own. Which scheme a message
 * uses is the composer's choice (compose--encryption).
 *
 * The keys come from the keyring (crypto/keyring.js) and the recipients'
 * keys from the server (ContactKeyController::recipients()); the server
 * checks again before sending that each recipient's key is still the one
 * used (App\Service\Crypto\SealGuard).
 */

import { boundary, bytesToBase64, normalise } from "./mime.js";

const CRLF = "\r\n";
const encoder = new TextEncoder();

/**
 * The person's own key for an address: active, bound to it, the primary one
 * when there are several. Mirrors App\Service\Crypto\SendingKey.
 */
export function ownKeyEntryFor(ring, email) {
    const address = String(email).toLowerCase();
    let found = null;

    for (const entry of ring.state?.addressKeys ?? []) {
        if ("active" !== entry.status) {
            continue;
        }

        for (const binding of entry.addresses ?? []) {
            if (address !== String(binding.email).toLowerCase()) {
                continue;
            }

            if (true === binding.primary) {
                return entry;
            }

            found ??= entry;
        }
    }

    return found;
}

/**
 * The person's own S/MIME certificate for an address: active, for it, valid
 * now, and of those the one that lasts longest — the keyring lists them so.
 * Mirrors App\Service\Crypto\SmimeCertificates::for().
 */
export function ownCertificateFor(ring, email) {
    const address = String(email).toLowerCase();
    const now = Date.now();

    return (ring.state?.smimeCertificates ?? []).find((entry) => "active" === entry.status
        && entry.addresses.includes(address)
        && Date.parse(entry.notBefore) <= now
        && now < Date.parse(entry.notAfter)) ?? null;
}

/** @returns {Promise<string>} the draft, armored, for the person's eyes only */
export async function sealDraft(ring, draft) {
    const openpgp = await import("openpgp");

    return openpgp.encrypt({
        message: await openpgp.createMessage({ text: JSON.stringify({ v: 1, ...draft }) }),
        encryptionKeys: await ownPublicKeys(ring),
        format: "armored",
    });
}

/** @returns {Promise<{html: string, text: string, plain: boolean}>} */
export async function openDraft(ring, armored) {
    const openpgp = await import("openpgp");
    const { data } = await openpgp.decrypt({
        message: await openpgp.readMessage({ armoredMessage: armored }),
        decryptionKeys: await ring.decryptionKeys(),
    });
    const draft = JSON.parse(data);

    return { html: String(draft.html ?? ""), text: String(draft.text ?? ""), plain: true === draft.plain };
}

/** @returns {Promise<Uint8Array>} a file, as an OpenPGP message to the person's own keys */
export async function sealFile(ring, bytes) {
    const openpgp = await import("openpgp");

    return openpgp.encrypt({
        message: await openpgp.createMessage({ binary: bytes }),
        encryptionKeys: await ownPublicKeys(ring),
        format: "binary",
    });
}

/** @returns {Promise<Uint8Array>} */
export async function openFile(ring, bytes) {
    const openpgp = await import("openpgp");
    const { data } = await openpgp.decrypt({
        message: await openpgp.readMessage({ binaryMessage: bytes }),
        decryptionKeys: await ring.decryptionKeys(),
        format: "binary",
    });

    return data;
}

/**
 * The message as it goes out, encrypted.
 *
 * @param {object} options
 * @param {object} options.ring
 * @param {string} options.entity           what buildEntity() made
 * @param {?string} options.inlineText      set for inline PGP: the text, and nothing else is sent
 * @param {string[]} options.recipientKeys  armored public keys, one per recipient
 * @param {object} options.signer           the keyring entry to sign with
 * @param {boolean} options.hideRecipients  when there are Bcc recipients: no key ids on the outside
 * @returns {Promise<{entity: string, fingerprints: string[]}>}
 */
export async function encryptEntity({ ring, entity, inlineText = null, recipientKeys, signer, hideRecipients = false }) {
    const openpgp = await import("openpgp");
    const keys = await Promise.all(recipientKeys.map((armoredKey) => openpgp.readKey({ armoredKey })));
    const own = await openpgp.readKey({ armoredKey: signer.publicKey });
    const encryptionKeys = unique([...keys, own]);
    const signingKeys = await signingKeyOf(ring, signer);

    const armored = await openpgp.encrypt({
        message: null === inlineText
            ? await openpgp.createMessage({ binary: encoder.encode(entity) })
            : await openpgp.createMessage({ text: normalise(inlineText) }),
        encryptionKeys,
        signingKeys,
        format: "armored",
        // A Bcc recipient's key id would tell everybody else they were sent
        // the message. Wildcard ids say nothing; every recipient's program
        // then tries its own keys, which is what they are for.
        wildcard: hideRecipients,
    });

    const fingerprints = encryptionKeys.map((key) => key.getFingerprint().toLowerCase());

    if (null !== inlineText) {
        return {
            entity: ["Content-Type: text/plain; charset=utf-8", "Content-Transfer-Encoding: 7bit", "", normalise(armored.trim()), ""].join(CRLF),
            fingerprints,
        };
    }

    const marker = boundary();

    return {
        entity: [
            `Content-Type: multipart/encrypted; protocol="application/pgp-encrypted"; boundary="${marker}"`,
            "",
            "This is an OpenPGP/MIME encrypted message (RFC 4880 and 3156)",
            `--${marker}`,
            "Content-Type: application/pgp-encrypted",
            "Content-Description: PGP/MIME version identification",
            "",
            "Version: 1",
            "",
            `--${marker}`,
            'Content-Type: application/octet-stream; name="encrypted.asc"',
            "Content-Description: OpenPGP encrypted message",
            'Content-Disposition: inline; filename="encrypted.asc"',
            "",
            normalise(armored.trim()),
            "",
            `--${marker}--`,
            "",
        ].join(CRLF),
        fingerprints,
    };
}

/**
 * The message as it goes out, signed: the entity untouched, beside a detached
 * signature over exactly its bytes.
 *
 * @returns {Promise<string>}
 */
export async function signEntity({ ring, entity, signer }) {
    const openpgp = await import("openpgp");
    // What sits between the two boundaries, which is what a verifier hashes:
    // the CRLF in front of a boundary belongs to the boundary.
    const signed = entity.replace(/\r\n$/, "");
    const armoredSignature = await openpgp.sign({
        message: await openpgp.createMessage({ binary: encoder.encode(signed) }),
        signingKeys: await signingKeyOf(ring, signer),
        detached: true,
        format: "armored",
    });
    const signature = await openpgp.readSignature({ armoredSignature });
    const hash = openpgp.enums.read(openpgp.enums.hash, signature.packets[0].hashAlgorithm).toLowerCase();
    const marker = boundary();

    return [
        `Content-Type: multipart/signed; micalg=pgp-${hash}; protocol="application/pgp-signature"; boundary="${marker}"`,
        "",
        "This is an OpenPGP/MIME signed message (RFC 4880 and 3156)",
        `--${marker}`,
        signed,
        `--${marker}`,
        'Content-Type: application/pgp-signature; name="signature.asc"',
        "Content-Description: OpenPGP digital signature",
        'Content-Disposition: attachment; filename="signature.asc"',
        "",
        normalise(armoredSignature.trim()),
        "",
        `--${marker}--`,
        "",
    ].join(CRLF);
}

/**
 * The message as it goes out, S/MIME-encrypted: signed first, the way
 * Thunderbird and Outlook do it, then enveloped to every recipient's
 * certificate and to the sender's, so the Sent copy stays readable.
 *
 * @param {object} options
 * @param {object} options.ring
 * @param {string} options.entity                  what buildEntity() made
 * @param {string[]} options.recipientCertificates PEM, one per recipient
 * @param {object} options.signer                  the keyring's S/MIME entry to sign with
 * @returns {Promise<{entity: string, fingerprints: string[]}>}
 */
export async function smimeEncryptEntity({ ring, entity, recipientCertificates, signer }) {
    const smime = await import("./smime.js");
    const signed = await smimeSignEntity({ ring, entity, signer });
    const certificates = uniqueBy([...recipientCertificates, signer.certificate], (pem) => pem.replace(/\s+/g, ""));
    const enveloped = await smime.envelope(encoder.encode(signed), certificates);
    const fingerprints = await Promise.all(certificates.map((pem) => smime.fingerprintOf(smime.pemToDer(pem))));

    return {
        entity: [
            'Content-Type: application/pkcs7-mime; smime-type=enveloped-data; name="smime.p7m"',
            "Content-Transfer-Encoding: base64",
            'Content-Disposition: attachment; filename="smime.p7m"',
            "Content-Description: S/MIME Encrypted Message",
            "",
            wrap76(bytesToBase64(enveloped)),
            "",
        ].join(CRLF),
        fingerprints: [...new Set(fingerprints)],
    };
}

/**
 * The message as it goes out, S/MIME-signed: the entity untouched, beside a
 * detached CMS signature over exactly its bytes (RFC 8551 §3.5.3).
 *
 * @returns {Promise<string>}
 */
export async function smimeSignEntity({ ring, entity, signer }) {
    const smime = await import("./smime.js");
    // What sits between the two boundaries, as signEntity() has it.
    const signed = entity.replace(/\r\n$/, "");
    const signature = await smime.sign(encoder.encode(signed), await ring.smimeSigner(signer.fingerprint));
    const marker = boundary();

    return [
        `Content-Type: multipart/signed; protocol="application/pkcs7-signature"; micalg=sha-256; boundary="${marker}"`,
        "",
        "This is a cryptographically signed message in MIME format.",
        `--${marker}`,
        signed,
        `--${marker}`,
        'Content-Type: application/pkcs7-signature; name="smime.p7s"',
        "Content-Transfer-Encoding: base64",
        'Content-Disposition: attachment; filename="smime.p7s"',
        "Content-Description: S/MIME Cryptographic Signature",
        "",
        wrap76(bytesToBase64(signature)),
        "",
        `--${marker}--`,
        "",
    ].join(CRLF);
}

// ── Private ─────────────────────────────────────────────────────────────────

async function ownPublicKeys(ring) {
    const openpgp = await import("openpgp");
    const active = (ring.state?.addressKeys ?? []).filter((entry) => "active" === entry.status);

    return Promise.all(active.map((entry) => openpgp.readKey({ armoredKey: entry.publicKey })));
}

async function signingKeyOf(ring, signer) {
    const fingerprint = String(signer.fingerprint).toLowerCase();

    for (const key of await ring.decryptionKeys()) {
        if (fingerprint === key.getFingerprint().toLowerCase()) {
            return key;
        }
    }

    throw new Error("no_signing_key");
}

function unique(keys) {
    const seen = new Set();

    return keys.filter((key) => {
        const fingerprint = key.getFingerprint();

        if (true === seen.has(fingerprint)) {
            return false;
        }

        seen.add(fingerprint);

        return true;
    });
}

function uniqueBy(items, keyOf) {
    const seen = new Set();

    return items.filter((item) => {
        const key = keyOf(item);

        if (true === seen.has(key)) {
            return false;
        }

        seen.add(key);

        return true;
    });
}

function wrap76(base64) {
    return (base64.match(/.{1,76}/g) ?? [""]).join(CRLF);
}
