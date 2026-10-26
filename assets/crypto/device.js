import { hasSubtle } from "./aead.js";

/**
 * This browser's own secret, for opening a user key after the password is gone.
 *
 * A non-extractable AES-GCM key in IndexedDB. The server keeps the user key
 * wrapped under it (a "device" recovery copy); this browser keeps the key and
 * nothing else. After a password reset, signing in here opens the old keyring
 * again without anybody being asked for anything — Proton's device recovery.
 *
 * Non-extractable, so a script running on the page can use it but never read
 * it out: what leaves the browser is at most a user key the person could open
 * anyway. That needs WebCrypto, so on plain HTTP there is no device key and
 * this answers null; the recovery phrase still works there.
 *
 * Kept per user id: two people sharing a browser each get their own.
 */

const DATABASE = "plmail-e2e";
const STORE = "device-keys";

/**
 * @param {number|string} userId
 * @param {boolean} create  make one when there is none
 * @returns {Promise<CryptoKey|null>}
 */
export async function deviceKey(userId, create = false) {
    if (false === hasSubtle() || undefined === globalThis.indexedDB) {
        return null;
    }

    try {
        const db = await database();
        const existing = await request(db.transaction(STORE).objectStore(STORE).get(String(userId)));

        if (existing instanceof CryptoKey || false === create) {
            return existing ?? null;
        }

        const key = await crypto.subtle.generateKey({ name: "AES-GCM", length: 256 }, false, ["encrypt", "decrypt"]);

        await request(db.transaction(STORE, "readwrite").objectStore(STORE).put(key, String(userId)));

        return key;
    } catch {
        return null;
    }
}

/**
 * What the list of known devices calls this one: the browser and the system,
 * which is what a person recognises a device by.
 *
 * @returns {string}
 */
export function deviceLabel() {
    const agent = navigator.userAgent;
    const browser = /Edg\//.test(agent) ? "Edge"
        : /Firefox\//.test(agent) ? "Firefox"
        : /Chrome\//.test(agent) ? "Chrome"
        : /Safari\//.test(agent) ? "Safari"
        : "Browser";
    const system = /Android/.test(agent) ? "Android"
        : /iPhone|iPad/.test(agent) ? "iOS"
        : /Mac OS X/.test(agent) ? "macOS"
        : /Windows/.test(agent) ? "Windows"
        : /Linux/.test(agent) ? "Linux"
        : "";

    return "" === system ? browser : `${browser} · ${system}`;
}

// ── Private ─────────────────────────────────────────────────────────────────

function database() {
    return new Promise((resolve, reject) => {
        const opening = indexedDB.open(DATABASE, 1);

        opening.onupgradeneeded = () => opening.result.createObjectStore(STORE);
        opening.onsuccess = () => resolve(opening.result);
        opening.onerror = () => reject(opening.error);
    });
}

function request(idbRequest) {
    return new Promise((resolve, reject) => {
        idbRequest.onsuccess = () => resolve(idbRequest.result);
        idbRequest.onerror = () => reject(idbRequest.error);
    });
}
