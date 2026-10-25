/**
 * Hands the keyring to a device being paired, the way Signal links a device:
 * the browser seals the person's user keys under a fresh transfer key, the
 * server keeps only the sealed copy beside the pairing code for its two
 * minutes, and the transfer key goes into the QR code's fragment — which no
 * HTTP request carries — and nowhere else.
 *
 * The app reads `#k=` from the pairing URI, gets `keys` with its app password
 * when it redeems the code, and opens it: AES-256-GCM, `v1.<iv>.<ciphertext>`
 * (base64), associated data PURPOSE.pairing. Inside, JSON:
 * `{"v": 1, "userKeys": [{"id": …, "key": "<base64>"}]}`. See
 * docs/CLIENT_DEVELOPMENT.md.
 *
 * The key lives in this module between the two halves of the flow — the form
 * that asks for a code (settings--device-keys) and the QR code drawn from the
 * answer (settings--pairing-qr) — and is taken once.
 */

import { PURPOSE, randomBytes, seal } from "./aead.js";

let pending = null;

/**
 * @returns {Promise<string>} the sealed keys, for the form to post
 */
export async function sealForPairing(ring) {
    const transferKey = randomBytes(32);
    const bundle = new TextEncoder().encode(JSON.stringify({ v: 1, userKeys: ring.userKeysForPairing() }));
    const sealed = await seal(transferKey, bundle, PURPOSE.pairing);

    pending = transferKey;

    return sealed;
}

/** The transfer key, once, as the URI fragment carries it (base64url). */
export function takeTransferKey() {
    const key = pending;

    pending = null;

    return null === key ? null : btoa(String.fromCharCode(...key)).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}
