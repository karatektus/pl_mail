import { PURPOSE, fromBase64, open, randomBytes, seal, toBase64 } from "./aead.js";

/**
 * The browser's half of keeping a keyring unlocked for a whole session.
 *
 * The key that opens the user key (the "wrap key", derived from the password)
 * is wrapped under a random unlock key and kept in localStorage; the unlock
 * key goes to the server, which keeps it in the session. Any page — a reload,
 * another tab — fetches the unlock key back and opens what is stored here.
 * Neither half opens anything alone, and the stored half is worthless once
 * the session ends. See App\Security\Crypto\SessionUnlock.
 *
 * localStorage can be missing or refuse (a private window, blocked site data).
 * Every access is guarded, and the cost of it failing is only that the
 * keyring asks for the password, never that anything breaks.
 */

const STORAGE_KEY = "plmail.e2e.session";

/**
 * Keeps a wrap key for this session.
 *
 * @param {Uint8Array} wrapKey
 * @returns {Promise<string>} the unlock key, base64, for the server
 */
export async function remember(wrapKey) {
    const unlockKey = randomBytes(32);

    store(await seal(unlockKey, wrapKey, PURPOSE.session));

    return toBase64(unlockKey);
}

/**
 * Wraps a new wrap key under the unlock key the session already has — after a
 * password change, when the old one opens nothing any more.
 *
 * @param {Uint8Array} wrapKey
 * @param {string} unlockUrl
 */
export async function replace(wrapKey, unlockUrl) {
    const { unlockKey } = await fetchUnlockKey(unlockUrl);

    if (null !== unlockKey) {
        store(await seal(unlockKey, wrapKey, PURPOSE.session));
    }
}

/**
 * The wrap key this session was unlocked with, or null when it was not, or no
 * longer can be.
 *
 * @param {string} unlockUrl
 * @returns {Promise<Uint8Array|null>}
 */
export async function recall(unlockUrl) {
    const stored = read();

    if (null === stored) {
        return null;
    }

    const { unlockKey, gone } = await fetchUnlockKey(unlockUrl);

    if (null === unlockKey) {
        // Cleared only when the server SAID the session has no unlock key.
        // A request that failed — and one the browser abandoned because the
        // page was left mid-flight is exactly that — says nothing about the
        // session, and clearing on it locked the keyring of everybody who
        // clicked on before the first page had finished opening it.
        if (true === gone) {
            forget();
        }

        return null;
    }

    try {
        return await open(unlockKey, stored, PURPOSE.session);
    } catch {
        forget();

        return null;
    }
}

export function forget() {
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Nothing stored is nothing to clear.
    }
}

// ── Private ─────────────────────────────────────────────────────────────────

/**
 * @returns {Promise<{unlockKey: Uint8Array|null, gone: boolean}>} gone: the
 *          server answered that this session holds no unlock key
 */
async function fetchUnlockKey(unlockUrl) {
    try {
        const response = await fetch(unlockUrl, { headers: { Accept: "application/json" }, cache: "no-store" });

        if (404 === response.status) {
            return { unlockKey: null, gone: true };
        }

        if (false === response.ok) {
            return { unlockKey: null, gone: false };
        }

        const { unlockKey } = await response.json();

        return { unlockKey: "string" === typeof unlockKey ? fromBase64(unlockKey) : null, gone: false };
    } catch {
        return { unlockKey: null, gone: false };
    }
}

function store(value) {
    try {
        localStorage.setItem(STORAGE_KEY, value);
    } catch {
        // See the module docblock: the keyring will ask for the password.
    }
}

function read() {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}
