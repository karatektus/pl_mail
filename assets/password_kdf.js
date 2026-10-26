/**
 * Stretches a password in the browser, so that it never has to leave it.
 *
 *     masterKey = PBKDF2-SHA256(password, salt, iterations)   // stays here
 *     authHash  = PBKDF2-SHA256(masterKey, password, 1)       // is sent
 *
 * The Bitwarden construction. The server stores a hash of `authHash` and
 * checks sign-ins against it like any password; it never sees the password or
 * the master key, and cannot get from the one it does see to either. The end-
 * to-end encryption of issue #28 will derive the key that unlocks a user's
 * private keys from the master key, which is why this has to happen here and
 * not on the server. App\Security\PasswordKdf is the server's half, and its
 * derive() must stay byte-for-byte what this computes — PasswordKdfTest pins
 * both to one vector.
 *
 * WebCrypto where the page has it, @noble/hashes where it does not. plMail is
 * also reached over plain HTTP on a LAN address, and `crypto.subtle` exists
 * only in a secure context — without the fallback, nobody on such an install
 * could sign in at all. The fallback is the same function in plain JavaScript
 * and several times slower — one to two seconds at 600 000 iterations on a
 * desktop, once per sign-in. It is loaded only when needed.
 *
 * NFC before anything else, because the same password is not always the same
 * bytes: "ü" comes precomposed from one keyboard and as "u" plus a combining
 * diaeresis from another.
 */

import { hkdf } from "./crypto/aead.js";

const encoder = new TextEncoder();

/** 16 bytes, matching PasswordKdf::SALT_BYTES. */
const SALT_BYTES = 16;

/**
 * @param {string} password
 * @param {string} salt        base64, as the server hands it out
 * @param {number} iterations
 * @returns {Promise<string>}  the auth hash, base64
 */
export async function deriveAuthHash(password, salt, iterations) {
    return (await derivePasswordSecrets(password, salt, iterations)).authHash;
}

/**
 * The auth hash, and the key that wraps the person's user key.
 *
 *     wrapKey = HKDF-SHA256(masterKey, info = "plmail/user-key-wrap")
 *
 * Both from the same master key, and that is the point of this whole file:
 * the server gets the first and can do nothing with it but check a sign-in;
 * the second never leaves the browser, and is what opens the keyring — see
 * crypto/keyring.js.
 *
 * @param {string} password
 * @param {string} salt
 * @param {number} iterations
 * @returns {Promise<{authHash: string, wrapKey: Uint8Array}>}
 */
export async function derivePasswordSecrets(password, salt, iterations) {
    const secret = encoder.encode(password.normalize("NFC"));
    const masterKey = await pbkdf2(secret, fromBase64(salt), iterations);

    return {
        authHash: toBase64(await pbkdf2(masterKey, secret, 1)),
        wrapKey: await hkdf(masterKey, "plmail/user-key-wrap"),
    };
}

/**
 * A fresh salt for a password about to be set, base64.
 *
 * getRandomValues rather than anything under crypto.subtle: it exists outside
 * a secure context too.
 *
 * @returns {string}
 */
export function newSalt() {
    return toBase64(crypto.getRandomValues(new Uint8Array(SALT_BYTES)));
}

// ── Private ─────────────────────────────────────────────────────────────────

/**
 * @param {Uint8Array} secret
 * @param {Uint8Array} salt
 * @param {number} iterations
 * @returns {Promise<Uint8Array>} 32 bytes
 */
async function pbkdf2(secret, salt, iterations) {
    if (undefined !== globalThis.crypto?.subtle) {
        const key = await crypto.subtle.importKey("raw", secret, "PBKDF2", false, ["deriveBits"]);
        const bits = await crypto.subtle.deriveBits({ name: "PBKDF2", hash: "SHA-256", salt, iterations }, key, 256);

        return new Uint8Array(bits);
    }

    const [{ pbkdf2Async }, { sha256 }] = await Promise.all([
        import("@noble/hashes/pbkdf2"),
        import("@noble/hashes/sha2"),
    ]);

    return pbkdf2Async(sha256, secret, salt, { c: iterations, dkLen: 32 });
}

/**
 * @param {Uint8Array} bytes
 * @returns {string}
 */
function toBase64(bytes) {
    return btoa(Array.from(bytes, (byte) => String.fromCharCode(byte)).join(""));
}

/**
 * @param {string} text
 * @returns {Uint8Array}
 */
function fromBase64(text) {
    return Uint8Array.from(atob(text), (char) => char.charCodeAt(0));
}
