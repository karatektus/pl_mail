/**
 * Wrapping secrets the server stores and cannot open.
 *
 *     v1.<base64 12-byte IV>.<base64 AES-256-GCM ciphertext with its tag>
 *
 * Every user key, recovery copy and address-key passphrase on the server is
 * one of these (App\Security\Crypto\Wrapped checks the shape). The purpose is
 * bound in as associated data, so a blob wrapped as one thing cannot be opened
 * as another — a device copy handed back where a user key was expected fails
 * rather than quietly opening.
 *
 * WebCrypto where the page has it, @noble/ciphers and @noble/hashes where it
 * does not: plMail is also reached over plain HTTP on a LAN address, where
 * `crypto.subtle` does not exist — the same reason password_kdf.js carries a
 * fallback. Only a CryptoKey (the non-extractable device key) needs WebCrypto
 * and cannot fall back; device.js answers null there instead.
 */

const encoder = new TextEncoder();

/** What each wrapping is for — the associated data that binds it. */
export const PURPOSE = Object.freeze({
    userKey: "plmail/user-key",
    token: "plmail/address-key-token",
    phrase: "plmail/user-key/phrase",
    device: "plmail/user-key/device",
    session: "plmail/session-unlock",
    // The keyring for a device being paired, sealed under the transfer key in
    // the QR code (crypto/pairing.js). App\Service\User\DevicePairingService::TRANSFER_PURPOSE.
    pairing: "plmail/pairing-transfer",
    // An S/MIME private key (PKCS#8), wrapped directly under a user key
    // (crypto/smime.js, App\Entity\Crypto\SmimeCertificate).
    smime: "plmail/smime-key",
});

const IV_BYTES = 12;

export function hasSubtle() {
    return undefined !== globalThis.crypto?.subtle;
}

/**
 * @param {number} length
 * @returns {Uint8Array}
 */
export function randomBytes(length) {
    return crypto.getRandomValues(new Uint8Array(length));
}

/**
 * @param {Uint8Array|CryptoKey} key  32 raw bytes, or an AES-GCM CryptoKey
 * @param {Uint8Array} plaintext
 * @param {string} purpose            one of PURPOSE
 * @returns {Promise<string>}
 */
export async function seal(key, plaintext, purpose) {
    const iv = randomBytes(IV_BYTES);
    const aad = encoder.encode(purpose);
    let ciphertext;

    if (true === hasSubtle()) {
        const cryptoKey = await asCryptoKey(key);

        ciphertext = new Uint8Array(await crypto.subtle.encrypt({ name: "AES-GCM", iv, additionalData: aad }, cryptoKey, plaintext));
    } else {
        const { gcm } = await import("@noble/ciphers/aes");

        ciphertext = gcm(rawKey(key), iv, aad).encrypt(plaintext);
    }

    return `v1.${toBase64(iv)}.${toBase64(ciphertext)}`;
}

/**
 * Opens a wrapping, or throws — a wrong key, a tampered blob and the wrong
 * purpose all fail the tag check alike.
 *
 * @param {Uint8Array|CryptoKey} key
 * @param {string} wrapped
 * @param {string} purpose
 * @returns {Promise<Uint8Array>}
 */
export async function open(key, wrapped, purpose) {
    const [version, ivText, ciphertextText] = String(wrapped).split(".");

    if ("v1" !== version || undefined === ivText || undefined === ciphertextText) {
        throw new Error("Not a wrapped secret.");
    }

    const iv = fromBase64(ivText);
    const ciphertext = fromBase64(ciphertextText);
    const aad = encoder.encode(purpose);

    if (true === hasSubtle()) {
        const cryptoKey = await asCryptoKey(key);

        return new Uint8Array(await crypto.subtle.decrypt({ name: "AES-GCM", iv, additionalData: aad }, cryptoKey, ciphertext));
    }

    const { gcm } = await import("@noble/ciphers/aes");

    return gcm(rawKey(key), iv, aad).decrypt(ciphertext);
}

/**
 * HKDF-SHA256 with an empty salt: every input here is already uniformly
 * random or the output of PBKDF2, so the extract step has nothing to fix, and
 * `info` is what keeps the keys for different jobs apart.
 *
 * @param {Uint8Array} ikm
 * @param {string} info
 * @returns {Promise<Uint8Array>} 32 bytes
 */
export async function hkdf(ikm, info) {
    if (true === hasSubtle()) {
        const key = await crypto.subtle.importKey("raw", ikm, "HKDF", false, ["deriveBits"]);
        const bits = await crypto.subtle.deriveBits(
            { name: "HKDF", hash: "SHA-256", salt: new Uint8Array(0), info: encoder.encode(info) },
            key,
            256,
        );

        return new Uint8Array(bits);
    }

    const [{ hkdf: nobleHkdf }, { sha256 }] = await Promise.all([
        import("@noble/hashes/hkdf"),
        import("@noble/hashes/sha2"),
    ]);

    return nobleHkdf(sha256, ikm, undefined, encoder.encode(info), 32);
}

/**
 * @param {Uint8Array} bytes
 * @returns {string}
 */
export function toBase64(bytes) {
    return btoa(Array.from(bytes, (byte) => String.fromCharCode(byte)).join(""));
}

/**
 * @param {string} text
 * @returns {Uint8Array}
 */
export function fromBase64(text) {
    return Uint8Array.from(atob(text), (char) => char.charCodeAt(0));
}

// ── Private ─────────────────────────────────────────────────────────────────

async function asCryptoKey(key) {
    if (key instanceof Uint8Array) {
        return crypto.subtle.importKey("raw", key, "AES-GCM", false, ["encrypt", "decrypt"]);
    }

    return key;
}

function rawKey(key) {
    if (false === key instanceof Uint8Array) {
        throw new Error("A CryptoKey needs WebCrypto, which this page does not have.");
    }

    return key;
}
