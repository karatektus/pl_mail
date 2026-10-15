import { PURPOSE, hasSubtle, open, randomBytes, seal, toBase64 } from "./aead.js";
import { deviceKey, deviceLabel } from "./device.js";
import { newPhrase, phraseKey } from "./recovery_phrase.js";
import * as session from "./session.js";
import { derivePasswordSecrets, newSalt } from "../password_kdf.js";

/**
 * A person's keyring, opened in their browser.
 *
 *     user key   32 random bytes, wrapped under the wrap key derived from the
 *                root: the password, or an encryption passphrase
 *     address    OpenPGP keys, each locked with a random passphrase ("token")
 *     keys       that is wrapped under the user key
 *     copies     the user key wrapped under a recovery phrase or a device key
 *     S/MIME     certificates from PKCS#12 files, their PKCS#8 keys wrapped
 *                directly under the user key (crypto/smime.js)
 *
 * Every piece of cryptography happens here; the server stores the wrapped
 * blobs and enforces the rules a browser cannot be trusted with alone (see
 * App\Service\Crypto\Keyring). Opened user keys live only in this module's
 * memory, for as long as the page — Turbo keeps it across navigations, and a
 * reload opens it again through session.js without asking anything.
 *
 * On the main thread rather than in a worker, for now. A module worker does
 * not see the page's import map, so openpgp and @noble would have to be
 * passed in by URL, and nothing in this phase is slow enough to block the page
 * — generating a Curve25519 key takes milliseconds. Decrypting large mail in
 * phase 3 is when a worker earns its keep.
 *
 * The root (state.root) is the login password for every account that has one,
 * and an encryption passphrase for an account that signs in only through
 * single sign-on (phase 6e). Both are stretched the same way, each with its own
 * salt; only the password can be checked by the server. Nothing is asked at
 * sign-in on a session the password form did not open: the keyring stays shut
 * until something needs it, and requireOpen() then asks once, through the
 * shared dialog (crypto/unlock_dialog.js), for whichever secret applies.
 *
 * One instance per page: keyring() below.
 */

const encoder = new TextEncoder();
const decoder = new TextDecoder();

let instance = null;

/**
 * @param {{stateUrl: string, unlockUrl: string, preloginUrl: string, csrf: string, userId: string|number, email: string}} config
 * @returns {Keyring}
 */
export function keyring(config) {
    instance ??= new Keyring(config);
    instance.configure(config);

    return instance;
}

export class Keyring {
    /** @type {object|null} the server's KeyringView */
    state = null;

    /**
     * "unset": an account on the passphrase that has not set encryption up.
     *
     * @type {"disabled"|"insecure"|"unset"|"locked"|"open"|"broken"|"unknown"}
     */
    status = "unknown";

    #config;
    #wrapKey = null;
    /** @type {Map<number, Uint8Array>} opened user keys, by id */
    #userKeys = new Map();
    /** @type {Set<number>} device copies this browser can open */
    #ownDeviceCopies = new Set();
    /** @type {Map<number, object>} decrypted openpgp private keys, by address key id */
    #openedAddressKeys = new Map();
    /** @type {Map<number, Uint8Array>} unwrapped S/MIME keys (PKCS#8), by certificate id */
    #openedSmimeKeys = new Map();
    /** @type {Promise|null} the one ensure() of this page */
    #ensuring = null;
    /** @type {Promise<boolean>|null} the dialog requireOpen() is waiting on */
    #asking = null;

    constructor(config) {
        this.#config = { ...config };
    }

    /**
     * Fills in what an earlier caller did not know. The password form asks
     * for the keyring with only the URLs it needs, and on a settings page it
     * connects before the boot controller does — whose config, with the user
     * id and address the keyring needs to make a first one, would otherwise
     * have been thrown away by the singleton.
     */
    configure(config) {
        for (const [name, value] of Object.entries(config)) {
            if (undefined !== value && null !== value && "" !== value) {
                this.#config[name] ??= value;
            }
        }
    }

    // ── Opening ─────────────────────────────────────────────────────────────

    /**
     * Opens the keyring if the session can, and makes sure it is complete:
     * a first keyring for an account that has none, the old one reopened from
     * this device after a password reset, a key for every address. Silent —
     * this runs on every full page load.
     *
     * Once per page, however many callers ask: the boot controller and the
     * settings page both do, and two that each found no keyring would each
     * make one.
     *
     * @returns {Promise<{status: string, created: boolean, recovered: number}>}
     */
    ensure() {
        this.#ensuring ??= this.#ensure().catch((error) => {
            this.#ensuring = null;

            throw error;
        });

        return this.#ensuring;
    }

    async #ensure() {
        await this.refresh();

        if (false === this.state.enabled) {
            return this.#settle("disabled");
        }

        // Plain http on a LAN address: no WebCrypto, so no device keys, and
        // openpgp.js leans on it too. A keyring made here would be one the
        // next HTTPS browser could open — but a half-working one, failing at
        // whichever step needs WebCrypto first, helps nobody. Signing in still
        // works there (password_kdf.js falls back); keys wait for HTTPS.
        if (false === hasSubtle()) {
            return this.#settle("insecure");
        }

        // Made on purpose, in Settings → Encryption, and nowhere else: a
        // passphrase is something the person chooses, and until they have
        // there is nothing to open and nothing to say about it.
        if ("passphrase" === this.root && 0 === this.state.userKeys.length) {
            return this.#settle("unset");
        }

        this.#wrapKey ??= await session.recall(this.#config.unlockUrl);

        // On the password, a session whose hash is still of the password
        // itself has nothing a stored wrap key could have come from.
        if (null === this.#wrapKey || ("password" === this.root && null === this.state.kdf)) {
            return this.#settle("locked");
        }

        return this.#afterUnlock();
    }

    /** What the keyring hangs off: "password" or "passphrase". */
    get root() {
        return this.state?.root ?? "password";
    }

    /**
     * The keyring, open — asking for the password or the passphrase if this
     * session has not opened it yet. For whatever needs it now: an encrypted
     * message, a draft about to be sealed, Settings → Encryption, pairing.
     *
     * One dialog however many ask at once: a thread of five encrypted
     * messages asks once, and all five wait on the answer. Cancelling it
     * resolves them all with the keyring still shut, which each shows the way
     * it always showed a locked keyring.
     *
     * @returns {Promise<{status: string, created: boolean, recovered: number}>}
     */
    async requireOpen() {
        let result;

        try {
            result = await this.ensure();
        } catch {
            return this.#settle("broken");
        }

        if ("locked" !== result.status) {
            return result;
        }

        this.#asking ??= import("./unlock_dialog.js")
            .then(({ askToUnlock }) => askToUnlock(this))
            .catch(() => false)
            .finally(() => { this.#asking = null; });

        await this.#asking;

        return "open" === this.status ? this.#ensuring : result;
    }

    /**
     * Opens the keyring with its root's secret, for a session the sign-in did
     * not leave unlocked — a remember-me cookie, single sign-on, the install
     * wizard. Throws "wrong_password", "wrong_passphrase" or "rate_limited".
     *
     * @param {string} secret the password, or the encryption passphrase
     * @returns {Promise<{status: string, created: boolean, recovered: number}>}
     */
    async unlock(secret) {
        if (null === this.state) {
            await this.refresh();
        }

        return this.#opened("passphrase" === this.root ? this.#unlockWithPassphrase(secret) : this.#unlockWithPassword(secret));
    }

    /**
     * Sets encryption up on an account without a password: the first keyring,
     * wrapped under a key stretched from the passphrase chosen here, with a
     * salt of its own. The passphrase goes nowhere; the server gets the salt,
     * the iteration count and the wrapped key, and keeps the session's half
     * of the unlock so the person is not asked again for the rest of it.
     *
     * @param {string} passphrase
     * @returns {Promise<{status: string, created: boolean, recovered: number}>}
     */
    async setUpPassphrase(passphrase) {
        await this.refresh();

        if ("passphrase" !== this.root || 0 < this.state.userKeys.length || null !== this.state.rootKdf?.salt) {
            throw new Error("already_set_up");
        }

        const salt = newSalt();
        const iterations = this.state.rootKdf.iterations;
        const { wrapKey } = await derivePasswordSecrets(passphrase, salt, iterations);

        return this.#opened((async () => {
            this.#wrapKey = wrapKey;
            await this.#create({ salt, iterations });
            await this.#keepForSession(wrapKey);

            return { ...await this.#afterUnlock(), created: true };
        })());
    }

    /**
     * A new encryption passphrase: every active user key opened with the
     * current one and wrapped under the new one, here, and sent with the new
     * salt. The login is not touched. Throws "wrong_passphrase" when the
     * current one opens nothing.
     *
     * @param {string} current
     * @param {string} next
     */
    async changePassphrase(current, next) {
        await this.refresh();

        const kdf = this.state.rootKdf;

        if ("passphrase" !== this.root || null === kdf?.salt) {
            throw new Error("no_passphrase");
        }

        const { wrapKey: oldKey } = await derivePasswordSecrets(current, kdf.salt, kdf.iterations);
        const salt = newSalt();
        const { wrapKey: newKey } = await derivePasswordSecrets(next, salt, kdf.iterations);
        const rewrapped = {};

        for (const userKey of this.#activeUserKeys()) {
            let bytes;

            try {
                bytes = await open(oldKey, userKey.wrapped, PURPOSE.userKey);
            } catch {
                throw new Error("wrong_passphrase");
            }

            rewrapped[userKey.id] = await seal(newKey, bytes, PURPOSE.userKey);
        }

        await this.#post(this.#url("passphrase"), { passphrase: { salt, iterations: kdf.iterations }, rewrapped });

        this.#wrapKey = newKey;
        await session.replace(newKey, this.#config.unlockUrl);
    }

    async #unlockWithPassword(password) {
        const params = await this.#kdfParameters();
        const { authHash, wrapKey } = await derivePasswordSecrets(password, params.salt, params.iterations);
        const unlockKey = await session.remember(wrapKey);
        const response = await this.#post(this.#config.unlockUrl, {
            authHash,
            password: true === params.migrate ? password : "",
            unlockKey,
        }, false);

        if (false === response.ok) {
            session.forget();

            throw new Error(429 === response.status ? "rate_limited" : "wrong_password");
        }

        this.#wrapKey = wrapKey;
        await this.refresh();

        return this.#afterUnlock();
    }

    /**
     * There is no hash of the passphrase anywhere to check it against: it is
     * right when it opens an active user key (AES-GCM refuses anything else),
     * and only then is the session's half registered.
     */
    async #unlockWithPassphrase(passphrase) {
        await this.refresh();

        const kdf = this.state.rootKdf;

        if (null === kdf?.salt) {
            throw new Error("wrong_passphrase");
        }

        const { wrapKey } = await derivePasswordSecrets(passphrase, kdf.salt, kdf.iterations);
        let opens = false;

        for (const userKey of this.#activeUserKeys()) {
            try {
                await open(wrapKey, userKey.wrapped, PURPOSE.userKey);
                opens = true;
                break;
            } catch {
                // Not this one; a wrong passphrase opens none.
            }
        }

        if (false === opens) {
            throw new Error("wrong_passphrase");
        }

        await this.#keepForSession(wrapKey);
        this.#wrapKey = wrapKey;

        return this.#afterUnlock();
    }

    /**
     * The session's half of the unlock for a keyring on the passphrase, which
     * the server keeps without a proof — see UnlockController::session().
     */
    async #keepForSession(wrapKey) {
        const unlockKey = await session.remember(wrapKey);
        const response = await this.#post(`${this.#config.unlockUrl}/session`, { unlockKey }, false);

        if (false === response.ok) {
            session.forget();

            throw new Error("unlock_refused");
        }
    }

    /**
     * An unlock that worked becomes this page's ensure() — every later caller
     * gets the open keyring rather than the "locked" the page started with —
     * and is announced, so what gave up on a shut keyring (the background
     * decrypter, a message that showed it was locked) can try again.
     */
    async #opened(unlocking) {
        const result = await unlocking;

        this.#ensuring = Promise.resolve(result);

        if ("open" === result.status) {
            window.dispatchEvent(new CustomEvent("crypto:keyring", { detail: result }));
        }

        return result;
    }

    async refresh() {
        const response = await fetch(this.#config.stateUrl, { headers: { Accept: "application/json" }, cache: "no-store" });

        if (false === response.ok) {
            throw new Error("keyring_unavailable");
        }

        this.state = await response.json();

        return this.state;
    }

    // ── What the settings page asks ─────────────────────────────────────────

    hasPhrase() {
        return this.#activeUserKeys().some((userKey) => this.state.copies.some((copy) => "phrase" === copy.kind && copy.userKeyId === userKey.id));
    }

    lockedUserKeys() {
        return this.state?.userKeys.filter((userKey) => "locked" === userKey.status) ?? [];
    }

    isOwnDeviceCopy(copyId) {
        return this.#ownDeviceCopies.has(copyId);
    }

    rememberedHere() {
        return this.#activeUserKeys().some((userKey) => this.state.copies.some((copy) => "device" === copy.kind && copy.userKeyId === userKey.id && this.#ownDeviceCopies.has(copy.id)));
    }

    // ── Recovery ────────────────────────────────────────────────────────────

    /**
     * Makes a new recovery phrase and stores the keyring wrapped under it,
     * replacing the old phrase. The words are returned once and kept nowhere.
     *
     * @returns {Promise<string>}
     */
    async createPhrase() {
        this.#assertOpen();

        const words = await newPhrase();
        const key = await phraseKey(words);

        for (const [id, bytes] of this.#userKeys) {
            await this.#post(this.#url("copies"), { userKeyId: id, kind: "phrase", wrapped: await seal(key, bytes, PURPOSE.phrase) });
        }

        return words;
    }

    /**
     * Opens locked keys with a recovery phrase and wraps them under the
     * current password, which makes them active again.
     *
     * @param {string} words
     * @returns {Promise<number>} how many keys were reopened; throws "invalid_phrase"
     */
    async recoverWithPhrase(words) {
        this.#assertOpen();

        const key = await phraseKey(words);

        if (null === key) {
            throw new Error("invalid_phrase");
        }

        const reopened = await this.#reopenLocked("phrase", () => key);

        if (0 === reopened) {
            throw new Error("phrase_opens_nothing");
        }

        return reopened;
    }

    /**
     * Keeps this browser as a known device: every open user key wrapped under
     * a key only this browser holds.
     */
    async rememberDevice() {
        this.#assertOpen();

        const key = await deviceKey(this.#config.userId, true);

        if (null === key) {
            throw new Error("no_device_storage");
        }

        for (const [id, bytes] of this.#userKeys) {
            await this.#post(this.#url("copies"), { userKeyId: id, kind: "device", wrapped: await seal(key, bytes, PURPOSE.device), label: deviceLabel() });
        }

        await this.#findOwnDeviceCopies();
    }

    async removeCopy(copyId) {
        await this.#post(this.#url(`copies/${copyId}/remove`), {});
        this.#ownDeviceCopies.delete(copyId);
    }

    // ── Address keys ────────────────────────────────────────────────────────

    /**
     * An existing OpenPGP private key, bound to whichever of the person's
     * addresses its user IDs name and made primary for them — the key their
     * contacts already know.
     *
     * @param {string} armored
     * @param {string} passphrase
     * @returns {Promise<{imported: number, skipped: number}>}
     */
    async importKeys(armored, passphrase) {
        this.#assertOpen();

        const openpgp = await import("openpgp");
        let keys;

        try {
            keys = await openpgp.readPrivateKeys({ armoredKeys: armored });
        } catch {
            throw new Error("not_a_private_key");
        }

        const userKeyId = this.#currentUserKeyId();
        const prepared = [];
        let skipped = 0;

        for (const key of keys) {
            const addresses = key.users
                .map((user) => user.userID?.email?.toLowerCase() ?? "")
                .filter((email) => this.state.addresses.includes(email));

            if (0 === addresses.length) {
                skipped++;
                continue;
            }

            let decrypted;

            try {
                decrypted = key.isDecrypted() ? key : await openpgp.decryptKey({ privateKey: key, passphrase });
            } catch {
                throw new Error("wrong_passphrase");
            }

            if (false === await usable(decrypted)) {
                throw new Error("hardware_key");
            }

            prepared.push(await this.#prepare(decrypted, "imported", [...new Set(addresses)].map((email) => ({ email, primary: true })), userKeyId));
        }

        if (0 < prepared.length) {
            await this.#post(this.#url("address-keys"), { userKeyId, addressKeys: prepared });
        }

        return { imported: prepared.length, skipped };
    }

    /**
     * Every private key on the keyring — old and replaced ones too, which is
     * what still opens old mail — with its revocation certificate, as one
     * armored file GnuPG, Thunderbird and Proton read. Locked with a
     * passphrase the person chooses here.
     *
     * @param {string} passphrase
     * @returns {Promise<string>}
     */
    async exportKeys(passphrase) {
        this.#assertOpen();

        const openpgp = await import("openpgp");
        const blocks = [];
        const exported = [];

        for (const entry of this.state.addressKeys) {
            const decrypted = await this.#openAddressKey(entry);

            if (null === decrypted) {
                continue;
            }

            blocks.push((await openpgp.encryptKey({ privateKey: decrypted, passphrase })).armor());
            exported.push(entry.id);

            if (null !== entry.revocationCertificate) {
                blocks.push(entry.revocationCertificate);
            }
        }

        // Handed out now, so none of these may be quietly replaced any more —
        // see AddressKey::$firstUsedAt.
        if (0 < exported.length) {
            await this.#post(this.#url("address-keys/used"), { ids: exported });
        }

        return blocks.join("\n");
    }

    /**
     * A new key for the addresses of an old one. The old one stays, marked
     * obsolete, to decrypt what was sent to it.
     */
    async renew(addressKeyId) {
        this.#assertOpen();

        const entry = this.state.addressKeys.find((key) => key.id === addressKeyId);

        if (undefined === entry) {
            return;
        }

        const userKeyId = this.#currentUserKeyId();
        const addresses = entry.addresses.map(({ email }) => ({ email, primary: true }));

        await this.#post(this.#url("address-keys"), {
            userKeyId,
            addressKeys: [await this.#generate(addresses, userKeyId)],
        });
    }

    async markCompromised(addressKeyId) {
        await this.#post(this.#url(`address-keys/${addressKeyId}/compromised`), {});
        await this.renew(addressKeyId);
    }

    async makePrimary(addressKeyId, email) {
        await this.#post(this.#url(`address-keys/${addressKeyId}/primary`), { email });
    }

    // ── S/MIME ──────────────────────────────────────────────────────────────

    /**
     * A certificate and its key from a PKCS#12 file. The file is opened here
     * and nowhere else; the server gets the certificate, its chain, and the
     * key wrapped under the newest open user key.
     *
     * @param {Uint8Array} bytes
     * @param {string} password
     * @returns {Promise<{fingerprint: string, addresses: string[]}>}
     * @throws {Error} what crypto/smime.js readPkcs12() throws, or no_matching_address
     */
    async importSmime(bytes, password) {
        this.#assertOpen();

        const smime = await import("./smime.js");
        const opened = await smime.readPkcs12(bytes, password);
        const described = await smime.describeCertificate(opened.certificate);

        if (false === described.addresses.some((email) => this.state.addresses.includes(email))) {
            throw new Error("no_matching_address");
        }

        const userKeyId = this.#currentUserKeyId();

        await this.#post(this.#url("smime"), {
            userKeyId,
            certificate: opened.certificate,
            chain: opened.chain,
            privateKey: await seal(this.#userKeys.get(userKeyId), opened.pkcs8, PURPOSE.smime),
        });

        return described;
    }

    /**
     * One certificate as a PKCS#12 file again, locked with a password the
     * person chooses here.
     *
     * @returns {Promise<Uint8Array>}
     */
    async exportSmime(id, password) {
        this.#assertOpen();

        const entry = this.#smimeEntry(id);
        const smime = await import("./smime.js");

        return smime.writePkcs12({ certificate: entry.certificate, chain: entry.chain, pkcs8: await this.#openSmimeKey(entry), password });
    }

    async removeSmime(id) {
        await this.#post(this.#url(`smime/${id}/remove`), {});
        this.#openedSmimeKeys.delete(id);
    }

    /**
     * Every S/MIME certificate this browser can open, with its key — retired
     * and expired ones too, since mail encrypted to them is still mail.
     *
     * @returns {Promise<Array<{certificate: string, pkcs8: Uint8Array}>>}
     */
    async smimeKeys() {
        this.#assertOpen();

        const keys = [];

        for (const entry of this.state.smimeCertificates ?? []) {
            try {
                keys.push({ certificate: entry.certificate, pkcs8: await this.#openSmimeKey(entry) });
            } catch {
                // Under a locked user key, or not opening: the others may do.
            }
        }

        return keys;
    }

    /**
     * What signs with the certificate $fingerprint: itself, its chain and its
     * key.
     *
     * @returns {Promise<{certificate: string, chain: string, pkcs8: Uint8Array}>}
     */
    async smimeSigner(fingerprint) {
        this.#assertOpen();

        const entry = (this.state.smimeCertificates ?? []).find((candidate) => candidate.fingerprint === String(fingerprint).toLowerCase());

        if (undefined === entry) {
            throw new Error("no_signing_key");
        }

        return { certificate: entry.certificate, chain: entry.chain, pkcs8: await this.#openSmimeKey(entry) };
    }

    /** Lower-case SHA-256 fingerprints of the person's own certificates. */
    ownSmimeFingerprints() {
        return (this.state?.smimeCertificates ?? []).map((entry) => String(entry.fingerprint).toLowerCase());
    }

    // ── Reading mail ────────────────────────────────────────────────────────

    /**
     * Every address key this browser can open, decrypted — old and replaced
     * ones too, since mail sent to them is still mail. Kept in memory once
     * opened: opening is a passphrase derivation per key, and a thread of
     * encrypted messages would pay it once per message otherwise.
     *
     * @returns {Promise<object[]>} openpgp PrivateKey objects
     */
    async decryptionKeys() {
        this.#assertOpen();

        const keys = [];

        for (const entry of this.state.addressKeys) {
            if (false === this.#openedAddressKeys.has(entry.id)) {
                try {
                    const opened = await this.#openAddressKey(entry);

                    if (null !== opened) {
                        this.#openedAddressKeys.set(entry.id, opened);
                    }
                } catch {
                    // A key that will not open is skipped, not fatal: the
                    // message may well be encrypted to one of the others.
                }
            }

            if (true === this.#openedAddressKeys.has(entry.id)) {
                keys.push(this.#openedAddressKeys.get(entry.id));
            }
        }

        return keys;
    }

    /**
     * The public halves of the person's own keys, and the keys they hold for
     * a sender (ContactKeyController::sender) — what a signature inside an
     * encrypted message is checked against. A contact's key that will not
     * read is left out rather than failing the whole check.
     *
     * @param {{publicKey: string}[]} contactKeys
     * @returns {Promise<object[]>} openpgp PublicKey objects
     */
    async verificationKeys(contactKeys = []) {
        const openpgp = await import("openpgp");
        const armored = [...(this.state?.addressKeys ?? []), ...contactKeys].map((entry) => entry.publicKey);
        const keys = await Promise.all(armored.map((armoredKey) => openpgp.readKey({ armoredKey }).catch(() => null)));

        return keys.filter((key) => null !== key);
    }

    /**
     * The opened user keys, as base64 — for a device being paired, which
     * gets them sealed under a key only its QR code carries (crypto/pairing.js).
     * Nothing else may ask for them.
     *
     * @returns {Array<{id: number, key: string}>}
     */
    userKeysForPairing() {
        this.#assertOpen();

        return [...this.#userKeys].map(([id, bytes]) => ({ id, key: toBase64(bytes) }));
    }

    /** Lower-case primary fingerprints of the person's own keys. */
    ownFingerprints() {
        return (this.state?.addressKeys ?? []).map((entry) => String(entry.fingerprint).toLowerCase());
    }

    // ── Password changes ────────────────────────────────────────────────────

    /**
     * Every active user key wrapped under a new password's wrap key, for the
     * password form to send with the change. Opened with the current password
     * rather than taken from memory, so a tab that never unlocked can still
     * change the password without losing a key.
     *
     * Null for a keyring on the passphrase: nothing hangs off the password,
     * so its change carries no key and leaves the session's wrap key alone.
     *
     * @returns {Promise<Record<number, string>|null>}
     */
    async rewrapFor(currentPassword, newWrapKey) {
        // The page's own ensure() first: right after a first sign-in it may
        // still be making the keyring, and a change sent before that lands
        // would carry no key to re-wrap — which the server, seeing one appear
        // a moment later, would rightly refuse.
        await this.ensure().catch(() => undefined);
        await this.refresh();

        if ("passphrase" === this.root) {
            return null;
        }

        const active = this.#activeUserKeys();

        if (0 === active.length || null === this.state.kdf) {
            return {};
        }

        const { wrapKey } = await derivePasswordSecrets(currentPassword, this.state.kdf.salt, this.state.kdf.iterations);
        const rewrapped = {};

        for (const userKey of active) {
            const bytes = await open(wrapKey, userKey.wrapped, PURPOSE.userKey);

            rewrapped[userKey.id] = await seal(newWrapKey, bytes, PURPOSE.userKey);
        }

        return rewrapped;
    }

    // ── Private ─────────────────────────────────────────────────────────────

    async #afterUnlock() {
        await this.#openUserKeys();
        await this.#findOwnDeviceCopies();

        let created = false;
        let recovered = await this.#reopenLocked("device", (copy) => this.#ownDeviceCopies.has(copy.id) ? deviceKey(this.#config.userId) : null);

        if (0 === this.#userKeys.size) {
            // An active key this password cannot open is not something to
            // paper over with a new keyring: it means the session holds the
            // wrong wrap key, and a new keyring would orphan the real one.
            if (0 < this.#activeUserKeys().length) {
                return this.#settle("broken");
            }

            // Never by itself on the passphrase: setUpPassphrase() makes it.
            if ("passphrase" === this.root) {
                return this.#settle("unset");
            }

            await this.#create();
            created = true;
        }

        await this.#addMissingAddressKeys();

        return { ...this.#settle("open"), created, recovered };
    }

    async #openUserKeys() {
        this.#userKeys.clear();

        for (const userKey of this.#activeUserKeys()) {
            try {
                this.#userKeys.set(userKey.id, await open(this.#wrapKey, userKey.wrapped, PURPOSE.userKey));
            } catch {
                // Left out: see #afterUnlock() for what an unopenable active
                // key means.
            }
        }
    }

    async #findOwnDeviceCopies() {
        this.#ownDeviceCopies.clear();

        const key = await deviceKey(this.#config.userId);

        if (null === key) {
            return;
        }

        for (const copy of this.state.copies.filter((candidate) => "device" === candidate.kind)) {
            try {
                await open(key, copy.wrapped, PURPOSE.device);
                this.#ownDeviceCopies.add(copy.id);
            } catch {
                // Another browser's.
            }
        }
    }

    /**
     * Opens each locked user key with a recovery copy of the given kind and
     * wraps it under the current password.
     *
     * @param {"phrase"|"device"} kind
     * @param {(copy: object) => (Uint8Array|CryptoKey|null|Promise<Uint8Array|CryptoKey|null>)} keyFor
     */
    async #reopenLocked(kind, keyFor) {
        let reopened = 0;

        for (const userKey of this.lockedUserKeys()) {
            for (const copy of this.state.copies.filter((candidate) => kind === candidate.kind && candidate.userKeyId === userKey.id)) {
                const key = await keyFor(copy);

                if (null === key) {
                    continue;
                }

                let bytes;

                try {
                    bytes = await open(key, copy.wrapped, "phrase" === kind ? PURPOSE.phrase : PURPOSE.device);
                } catch {
                    continue;
                }

                await this.#post(this.#url(`user-keys/${userKey.id}/rewrap`), { wrapped: await seal(this.#wrapKey, bytes, PURPOSE.userKey) });
                await this.#post(this.#url(`copies/${copy.id}/used`), {});
                this.#userKeys.set(userKey.id, bytes);
                reopened++;
                break;
            }
        }

        return reopened;
    }

    /**
     * The first keyring: a user key, a key for every address, and this
     * browser as a known device where it can be one. With the passphrase's
     * salt and iteration count when the keyring hangs off one.
     *
     * @param {{salt: string, iterations: number}|null} passphrase
     */
    async #create(passphrase = null) {
        const bytes = randomBytes(32);
        const wrapped = await seal(this.#wrapKey, bytes, PURPOSE.userKey);
        const addressKeys = [];

        for (const email of this.state.addresses) {
            addressKeys.push(await this.#generate([{ email, primary: true }], null, bytes));
        }

        const device = await deviceKey(this.#config.userId, true);

        await this.#post(this.#url(""), {
            userKey: wrapped,
            addressKeys,
            deviceCopy: null === device ? null : await seal(device, bytes, PURPOSE.device),
            deviceLabel: null === device ? null : deviceLabel(),
            ...(null === passphrase ? {} : { passphrase }),
        });

        const created = this.state.userKeys.find((userKey) => userKey.wrapped === wrapped);

        if (undefined !== created) {
            this.#userKeys.set(created.id, bytes);
        }

        await this.#findOwnDeviceCopies();
    }

    /**
     * A key for every address that has no active primary one — an alias added
     * since, or the addresses of a keyring rebuilt after a reset.
     */
    async #addMissingAddressKeys() {
        const covered = new Set();

        for (const entry of this.state.addressKeys) {
            if ("active" === entry.status && this.#userKeys.has(entry.userKeyId)) {
                entry.addresses.filter(({ primary }) => primary).forEach(({ email }) => covered.add(email));
            }
        }

        const missing = this.state.addresses.filter((email) => false === covered.has(email));

        if (0 === missing.length) {
            return;
        }

        const userKeyId = this.#currentUserKeyId();
        const addressKeys = [];

        for (const email of missing) {
            addressKeys.push(await this.#generate([{ email, primary: true }], userKeyId));
        }

        await this.#post(this.#url("address-keys"), { userKeyId, addressKeys });
    }

    async #generate(addresses, userKeyId, userKeyBytes = null) {
        const openpgp = await import("openpgp");
        const token = toBase64(randomBytes(32));
        const { privateKey, revocationCertificate } = await openpgp.generateKey({
            userIDs: addresses.map(({ email }) => ({ email })),
            passphrase: token,
            format: "object",
        });

        return this.#describe(privateKey, token, "generated", addresses, userKeyBytes ?? this.#userKeys.get(userKeyId), { revocationCertificate });
    }

    /**
     * An existing decrypted key, locked again with a fresh token for the
     * keyring.
     */
    async #prepare(decrypted, origin, addresses, userKeyId) {
        const openpgp = await import("openpgp");
        const token = toBase64(randomBytes(32));
        const locked = await openpgp.encryptKey({ privateKey: decrypted, passphrase: token });

        return this.#describe(locked, token, origin, addresses, this.#userKeys.get(userKeyId), { decrypted });
    }

    /**
     * The shape KeyringController takes. A generated key comes with the
     * revocation certificate openpgp made alongside it; an imported one gets
     * one made here — a revoked copy of its public half, which is what
     * importing a revocation does anywhere — where its primary half is present.
     */
    async #describe(privateKey, token, origin, addresses, userKeyBytes, { decrypted = null, revocationCertificate = null } = {}) {
        const openpgp = await import("openpgp");
        const expiry = await privateKey.getExpirationTime();

        if (null === revocationCertificate && null !== decrypted) {
            try {
                revocationCertificate = (await openpgp.revokeKey({ key: decrypted, format: "armored" })).publicKey;
            } catch {
                // A key whose primary half lives offline cannot revoke itself
                // here. GnuPG made a certificate when the key was made; it
                // lives there.
            }
        }

        return {
            fingerprint: privateKey.getFingerprint(),
            publicKey: privateKey.toPublic().armor(),
            privateKey: privateKey.armor(),
            token: await seal(userKeyBytes, encoder.encode(token), PURPOSE.token),
            origin,
            expiresAt: expiry instanceof Date ? expiry.toISOString() : null,
            revocationCertificate,
            addresses,
        };
    }

    async #openAddressKey(entry) {
        const userKeyBytes = this.#userKeys.get(entry.userKeyId);

        if (undefined === userKeyBytes) {
            return null;
        }

        const openpgp = await import("openpgp");
        const token = decoder.decode(await open(userKeyBytes, entry.token, PURPOSE.token));

        return openpgp.decryptKey({ privateKey: await openpgp.readPrivateKey({ armoredKey: entry.privateKey }), passphrase: token });
    }

    #smimeEntry(id) {
        const entry = (this.state.smimeCertificates ?? []).find((candidate) => candidate.id === id);

        if (undefined === entry) {
            throw new Error("no_such_certificate");
        }

        return entry;
    }

    async #openSmimeKey(entry) {
        if (false === this.#openedSmimeKeys.has(entry.id)) {
            const userKeyBytes = this.#userKeys.get(entry.userKeyId);

            if (undefined === userKeyBytes) {
                throw new Error("locked");
            }

            this.#openedSmimeKeys.set(entry.id, await open(userKeyBytes, entry.privateKey, PURPOSE.smime));
        }

        return this.#openedSmimeKeys.get(entry.id);
    }

    async #kdfParameters() {
        if (null !== this.state?.kdf) {
            return { ...this.state.kdf, migrate: false };
        }

        const response = await fetch(this.#config.preloginUrl, {
            method: "POST",
            headers: { Accept: "application/json" },
            body: new URLSearchParams({ email: this.#config.email }),
        });

        if (false === response.ok) {
            throw new Error("prelogin_failed");
        }

        return response.json();
    }

    #activeUserKeys() {
        return this.state?.userKeys.filter((userKey) => "active" === userKey.status) ?? [];
    }

    /** The newest open user key: where new address keys go. */
    #currentUserKeyId() {
        return Math.max(...this.#userKeys.keys());
    }

    #assertOpen() {
        if ("open" !== this.status || 0 === this.#userKeys.size) {
            throw new Error("locked");
        }
    }

    #settle(status) {
        this.status = status;

        return { status, created: false, recovered: 0 };
    }

    #url(path) {
        return "" === path ? this.#config.stateUrl : `${this.#config.stateUrl}/${path}`;
    }

    /**
     * POSTs JSON with the keyring's CSRF token. Every keyring write answers
     * with the whole keyring, which replaces what this instance holds.
     */
    async #post(url, body, expectKeyring = true) {
        const response = await fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": this.#config.csrf },
            body: JSON.stringify(body),
        });

        if (false === expectKeyring) {
            return response;
        }

        if (false === response.ok) {
            throw new Error((await response.json().catch(() => ({}))).error ?? "keyring_refused");
        }

        this.state = await response.json();

        return response;
    }
}

/**
 * Whether a decrypted key can actually sign and decrypt here — a key whose
 * secret halves live on a smartcard decrypts "successfully" into stubs.
 */
async function usable(key) {
    try {
        const signing = await key.getSigningKey();
        const decrypting = (await key.getDecryptionKeys())[0];

        return false === signing.keyPacket.isDummy() && undefined !== decrypting && false === decrypting.keyPacket.isDummy();
    } catch {
        return false;
    }
}
