import { Controller } from "@hotwired/stimulus";
import { askConfirm } from "../../confirm.js";
import { keyring } from "../../crypto/keyring.js";
import { showToast } from "../../toast.js";

/**
 * Settings → Encryption. See templates/settings/_encryption.html.twig for what
 * is on the page; crypto/keyring.js does every piece of work, this only
 * decides what to show and passes clicks on.
 *
 * Values:
 *   stateUrl, unlockUrl, preloginUrl, csrf, userId, email — the keyring's config
 *   locale     — for dates
 *   minLength  — User::PASSWORD_MIN_LENGTH, the floor for a passphrase too
 *   messages   — translated strings, keyed as in the template
 *
 * Targets: see the template; every one is optional so that a part of the page
 * left out does not take the rest down with it.
 */
export default class extends Controller {
    static targets = [
        "status", "unlockForm", "openOnly",
        "setupForm", "setupPassphrase", "setupRepeat", "setupError",
        "passphraseCard", "passphraseCurrent", "passphraseNew", "passphraseRepeat", "passphraseResult",
        "phraseState", "phraseCreate", "phraseBox", "phraseWords", "recoverForm", "recoverWords", "recoverResult",
        "devices", "rememberDevice", "keys",
        "importFile", "importText", "importPassphrase", "importResult", "exportPassphrase",
        "smimeList", "smimeFile", "smimePassword", "smimeResult", "smimeExportPassword",
    ];

    static values = {
        stateUrl: String,
        unlockUrl: String,
        preloginUrl: String,
        csrf: String,
        userId: String,
        email: String,
        locale: String,
        minLength: { type: Number, default: 12 },
        messages: Object,
    };

    #ring = null;
    #phrase = null;

    async connect() {
        this.#ring = keyring({
            stateUrl: this.stateUrlValue,
            unlockUrl: this.unlockUrlValue,
            preloginUrl: this.preloginUrlValue,
            csrf: this.csrfValue,
            userId: this.userIdValue,
            email: this.emailValue,
        });

        // The page that is all about the keyring: a shut one is asked open
        // straight away, through the shared dialog.
        await this.#ring.requireOpen();

        this.#render();
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    /** The status card's button, for asking again after the dialog was cancelled. */
    async unlock(event) {
        await this.#busy(event.currentTarget, () => this.#ring.requireOpen());
    }

    clearValidity(event) {
        event.target.setCustomValidity("");
    }

    /**
     * Encryption, set up with a passphrase, on an account that has no password
     * for it to hang off. See Keyring.setUpPassphrase().
     */
    async setUp(event) {
        event.preventDefault();

        if (false === this.#acceptable(this.setupPassphraseTarget, this.setupRepeatTarget)) {
            return;
        }

        await this.#busy(event.submitter, async () => {
            try {
                await this.#ring.setUpPassphrase(this.setupPassphraseTarget.value);
                this.setupPassphraseTarget.value = "";
                this.setupRepeatTarget.value = "";
                this.setupErrorTarget.hidden = true;
                showToast(this.messagesValue.setupDone, { type: "success" });
            } catch (error) {
                console.warn("Keyring:", error);
                this.setupErrorTarget.textContent = this.messagesValue.failed;
                this.setupErrorTarget.hidden = false;
            }
        });
    }

    /** A new encryption passphrase. See Keyring.changePassphrase(). */
    async changePassphrase(event) {
        event.preventDefault();

        if (false === this.#acceptable(this.passphraseNewTarget, this.passphraseRepeatTarget)) {
            return;
        }

        await this.#busy(event.submitter, async () => {
            try {
                await this.#ring.changePassphrase(this.passphraseCurrentTarget.value, this.passphraseNewTarget.value);
                this.passphraseCurrentTarget.value = "";
                this.passphraseNewTarget.value = "";
                this.passphraseRepeatTarget.value = "";
                this.#say(this.passphraseResultTarget, this.messagesValue.passphraseChanged, false);
            } catch (error) {
                this.#say(this.passphraseResultTarget, "wrong_passphrase" === error.message ? this.messagesValue.wrongRootPassphrase : this.messagesValue.failed, true);
            }
        });
    }

    async createPhrase(event) {
        await this.#busy(event.currentTarget, async () => {
            this.#phrase = await this.#ring.createPhrase();
            this.phraseWordsTarget.textContent = this.#phrase;
            this.phraseBoxTarget.hidden = false;
        });
    }

    /**
     * The phrase as a text file, together with the revocation certificate of
     * every key — the one thing left that can tell contacts to stop using a key
     * on the day the password and the phrase are both gone.
     */
    downloadPhrase() {
        if (null === this.#phrase) {
            return;
        }

        const certificates = this.#ring.state.addressKeys
            .map((key) => key.revocationCertificate)
            .filter((certificate) => null !== certificate);

        this.#download(this.messagesValue.phraseFileName, [this.messagesValue.phraseFileIntro, "", this.#phrase, "", ...certificates].join("\n"));
    }

    async recover(event) {
        event.preventDefault();

        await this.#busy(event.submitter, async () => {
            try {
                const reopened = await this.#ring.recoverWithPhrase(this.recoverWordsTarget.value);

                this.recoverWordsTarget.value = "";
                this.recoverResultTarget.hidden = true;
                // A toast, not the line under the form: the form goes away as
                // soon as no key is left locked, and takes its line with it.
                showToast(this.messagesValue.phraseRecovered.replace("%count%", reopened), { type: "success" });
            } catch (error) {
                const message = "invalid_phrase" === error.message ? this.messagesValue.phraseInvalid
                    : "phrase_opens_nothing" === error.message ? this.messagesValue.phraseOpensNothing
                    : this.messagesValue.failed;

                this.#say(this.recoverResultTarget, message, true);
            }
        });
    }

    async rememberDevice(event) {
        await this.#busy(event.currentTarget, async () => {
            try {
                await this.#ring.rememberDevice();
            } catch (error) {
                showToast("no_device_storage" === error.message ? this.messagesValue.noDeviceStorage : this.messagesValue.failed, { type: "error" });
            }
        });
    }

    async removeCopy(event) {
        await this.#busy(event.currentTarget, () => this.#ring.removeCopy(Number(event.params.id)));
    }

    async importKeys(event) {
        event.preventDefault();

        await this.#busy(event.submitter, async () => {
            const file = this.importFileTarget.files?.[0];
            const armored = undefined !== file ? await file.text() : this.importTextTarget.value;

            try {
                const { imported } = await this.#ring.importKeys(armored, this.importPassphraseTarget.value);

                this.importTextTarget.value = "";
                this.importPassphraseTarget.value = "";
                this.importFileTarget.value = "";
                this.#say(this.importResultTarget, 0 === imported ? this.messagesValue.importNoMatch : this.messagesValue.importDone.replace("%count%", imported), 0 === imported);
            } catch (error) {
                const message = {
                    wrong_passphrase: this.messagesValue.wrongPassphrase,
                    hardware_key: this.messagesValue.hardwareKey,
                    not_a_private_key: this.messagesValue.notAKey,
                }[error.message] ?? this.messagesValue.failed;

                this.#say(this.importResultTarget, message, true);
            }
        });
    }

    async exportKeys(event) {
        event.preventDefault();

        await this.#busy(event.submitter, async () => {
            const armored = await this.#ring.exportKeys(this.exportPassphraseTarget.value);

            this.exportPassphraseTarget.value = "";
            this.#download(this.messagesValue.exportFileName, armored);
        });
    }

    async renew(event) {
        if (false === await askConfirm(this.messagesValue.confirmRenew)) {
            return;
        }

        await this.#busy(event.currentTarget, () => this.#ring.renew(Number(event.params.id)));
    }

    async markCompromised(event) {
        if (false === await askConfirm(this.messagesValue.confirmCompromised)) {
            return;
        }

        await this.#busy(event.currentTarget, () => this.#ring.markCompromised(Number(event.params.id)));
    }

    async makePrimary(event) {
        await this.#busy(event.currentTarget, () => this.#ring.makePrimary(Number(event.params.id), event.params.email));
    }

    /**
     * A PKCS#12 file into the keyring. The file and its password stay in
     * this browser; see Keyring.importSmime().
     */
    async importSmime(event) {
        event.preventDefault();

        await this.#busy(event.submitter, async () => {
            const file = this.smimeFileTarget.files?.[0];

            if (undefined === file) {
                return;
            }

            try {
                const imported = await this.#ring.importSmime(new Uint8Array(await file.arrayBuffer()), this.smimePasswordTarget.value);

                this.smimeFileTarget.value = "";
                this.smimePasswordTarget.value = "";
                this.#say(this.smimeResultTarget, this.messagesValue.smimeImported.replace("%addresses%", imported.addresses.join(", ")), false);
            } catch (error) {
                const message = {
                    wrong_password: this.messagesValue.smimeWrongPassword,
                    not_pkcs12: this.messagesValue.smimeNotPkcs12,
                    legacy_pkcs12: this.messagesValue.smimeLegacy,
                    not_rsa: this.messagesValue.smimeNotRsa,
                    no_key: this.messagesValue.smimeNoKey,
                    no_certificate: this.messagesValue.smimeNoKey,
                    no_matching_address: this.messagesValue.smimeNoMatch,
                }[error.message] ?? this.messagesValue.failed;

                this.#say(this.smimeResultTarget, message, true);
            }
        });
    }

    /** One certificate as a .p12 file, locked with the password typed beside the list. */
    async exportSmime(event) {
        const password = this.smimeExportPasswordTarget.value;

        if (password.length < 8) {
            showToast(this.messagesValue.smimeExportPasswordMissing, { type: "error" });
            this.smimeExportPasswordTarget.focus();

            return;
        }

        await this.#busy(event.currentTarget, async () => {
            const id = Number(event.params.id);
            const bytes = await this.#ring.exportSmime(id, password);
            const entry = this.#ring.state.smimeCertificates.find((candidate) => candidate.id === id);

            this.smimeExportPasswordTarget.value = "";
            this.#download(`${(entry?.addresses[0] ?? "certificate").replace(/[^a-z0-9@._-]/gi, "_")}.p12`, bytes, "application/x-pkcs12");
        });
    }

    async removeSmime(event) {
        if (false === await askConfirm(this.messagesValue.smimeConfirmRemove)) {
            return;
        }

        await this.#busy(event.currentTarget, () => this.#ring.removeSmime(Number(event.params.id)));
    }

    // ── Private ─────────────────────────────────────────────────────────────

    #render() {
        const ring = this.#ring;
        const open = "open" === ring.status;

        this.#renderStatus();
        this.openOnlyTargets.forEach((element) => { element.hidden = false === open; });

        if (true === this.hasUnlockFormTarget) {
            this.unlockFormTarget.hidden = "locked" !== ring.status;
        }

        if (true === this.hasSetupFormTarget) {
            this.setupFormTarget.hidden = "unset" !== ring.status;
        }

        if (true === this.hasPassphraseCardTarget) {
            this.passphraseCardTarget.hidden = false === open || "passphrase" !== ring.root;
        }

        if (false === open) {
            return;
        }

        this.phraseStateTarget.textContent = ring.hasPhrase() ? this.messagesValue.phraseSaved : this.messagesValue.phraseMissing;
        this.phraseCreateTarget.textContent = ring.hasPhrase() ? this.messagesValue.phraseRecreate : this.messagesValue.phraseCreate;
        this.recoverFormTarget.hidden = 0 === ring.lockedUserKeys().length;

        this.#renderDevices();
        this.#renderKeys();
        this.#renderSmime();
    }

    #renderStatus() {
        const state = this.#ring.state;
        const [icon, text] = {
            open: ["fa-solid fa-lock text-emerald-500", this.messagesValue.statusOpen],
            locked: ["fa-solid fa-lock text-amber-500", "passphrase" === this.#ring.root ? this.messagesValue.statusLockedPassphrase : this.messagesValue.statusLocked],
            unset: ["fa-solid fa-circle-minus text-ink-faint", this.messagesValue.statusUnset],
            disabled: ["fa-solid fa-circle-minus text-ink-faint", this.messagesValue.statusDisabled],
            insecure: ["fa-solid fa-circle-minus text-amber-500", this.messagesValue.statusInsecure],
            broken: ["fa-solid fa-triangle-exclamation text-danger", this.messagesValue.statusBroken],
        }[this.#ring.status] ?? ["fa-solid fa-spinner fa-spin text-ink-faint", this.messagesValue.statusLoading];

        const line = document.createElement("span");

        line.textContent = text;

        if ("open" === this.#ring.status && null !== state) {
            const keys = state.addressKeys.filter((key) => "active" === key.status).length;
            const detail = document.createElement("span");

            detail.className = "block text-[11px] text-ink-faint mt-0.5";
            detail.textContent = this.messagesValue.keyCount
                .replace("%keys%", keys)
                .replace("%addresses%", state.addresses.length);
            line.append(detail);
        }

        this.statusTarget.replaceChildren(this.#icon(icon, "mt-0.5"), line);
    }

    #renderDevices() {
        const copies = this.#ring.state.copies.filter((copy) => "device" === copy.kind);

        this.rememberDeviceTarget.hidden = this.#ring.rememberedHere();

        if (0 === copies.length) {
            const empty = document.createElement("li");

            empty.className = "py-2 text-xs text-ink-faint italic";
            empty.textContent = this.messagesValue.devicesEmpty;
            this.devicesTarget.replaceChildren(empty);

            return;
        }

        this.devicesTarget.replaceChildren(...copies.map((copy) => {
            const row = document.createElement("li");
            const text = document.createElement("div");
            const name = document.createElement("p");
            const meta = document.createElement("p");

            row.className = "flex items-center justify-between gap-3 py-2";
            text.className = "min-w-0";
            name.className = "text-sm text-ink";
            name.textContent = copy.label ?? "—";

            if (this.#ring.isOwnDeviceCopy(copy.id)) {
                const tag = document.createElement("span");

                tag.className = "ml-2 text-[11px] text-accent font-medium";
                tag.textContent = this.messagesValue.thisDevice;
                name.append(tag);
            }

            meta.className = "text-[11px] text-ink-faint";
            meta.textContent = [
                this.#date(copy.createdAt),
                null === copy.lastUsedAt ? null : `${this.messagesValue.lastUsed} ${this.#date(copy.lastUsedAt)}`,
            ].filter(Boolean).join(" · ");

            text.append(name, meta);
            row.append(text, this.#button(this.messagesValue.remove, "removeCopy", { id: copy.id }));

            return row;
        }));
    }

    #renderKeys() {
        const lockedIds = new Set(this.#ring.lockedUserKeys().map((userKey) => userKey.id));

        this.keysTarget.replaceChildren(...this.#ring.state.addressKeys.map((key) => {
            const row = document.createElement("li");
            const addresses = document.createElement("p");
            const meta = document.createElement("p");
            const fingerprint = document.createElement("p");
            const actions = document.createElement("div");

            row.className = "py-3";
            addresses.className = "text-sm text-ink break-all";
            addresses.textContent = key.addresses.map(({ email, primary }) => primary ? `${email} (${this.messagesValue.primary})` : email).join(", ");

            meta.className = "text-[11px] text-ink-faint mt-0.5";
            meta.textContent = [
                { active: this.messagesValue.statusActive, obsolete: this.messagesValue.statusObsolete, compromised: this.messagesValue.statusCompromised }[key.status],
                "generated" === key.origin ? this.messagesValue.originGenerated : this.messagesValue.originImported,
                `${this.messagesValue.created} ${this.#date(key.createdAt)}`,
                null === key.expiresAt ? null : `${this.messagesValue.expires} ${this.#date(key.expiresAt)}`,
                lockedIds.has(key.userKeyId) ? this.messagesValue.lockedKey : null,
            ].filter(Boolean).join(" · ");

            fingerprint.className = "text-[11px] font-mono text-ink-muted mt-1 break-all";
            fingerprint.textContent = key.fingerprint.toUpperCase().match(/.{1,4}/g).join(" ");

            actions.className = "flex flex-wrap gap-2 mt-2";

            if ("active" === key.status && false === lockedIds.has(key.userKeyId)) {
                key.addresses
                    .filter(({ primary }) => false === primary)
                    .forEach(({ email }) => actions.append(this.#button(`${this.messagesValue.makePrimary}: ${email}`, "makePrimary", { id: key.id, email })));
                actions.append(this.#button(this.messagesValue.renew, "renew", { id: key.id }));
                actions.append(this.#button(this.messagesValue.compromised, "markCompromised", { id: key.id }));
            }

            row.append(addresses, meta, fingerprint, actions);

            return row;
        }));
    }

    #renderSmime() {
        if (false === this.hasSmimeListTarget) {
            return;
        }

        const certificates = this.#ring.state.smimeCertificates ?? [];

        if (0 === certificates.length) {
            const empty = document.createElement("li");

            empty.className = "py-2 text-xs text-ink-faint italic";
            empty.textContent = this.messagesValue.smimeEmpty;
            this.smimeListTarget.replaceChildren(empty);

            return;
        }

        const lockedIds = new Set(this.#ring.lockedUserKeys().map((userKey) => userKey.id));

        this.smimeListTarget.replaceChildren(...certificates.map((certificate) => {
            const row = document.createElement("li");
            const addresses = document.createElement("p");
            const meta = document.createElement("p");
            const fingerprint = document.createElement("p");
            const actions = document.createElement("div");
            const expired = Date.parse(certificate.notAfter) <= Date.now();

            row.className = "py-3";
            row.setAttribute("data-testid", "smime-certificate");
            addresses.className = "text-sm text-ink break-all";
            addresses.textContent = `${certificate.subject} · ${certificate.addresses.join(", ")}`;

            meta.className = "text-[11px] text-ink-faint mt-0.5";
            meta.textContent = [
                `${this.messagesValue.smimeIssuedBy} ${certificate.issuer}`,
                `${true === expired ? this.messagesValue.smimeExpired : this.messagesValue.smimeValidUntil} ${this.#date(certificate.notAfter)}`,
                "retired" === certificate.status ? this.messagesValue.smimeRetired : null,
                lockedIds.has(certificate.userKeyId) ? this.messagesValue.lockedKey : null,
            ].filter(Boolean).join(" · ");

            fingerprint.className = "text-[11px] font-mono text-ink-muted mt-1 break-all";
            fingerprint.textContent = certificate.fingerprint.toUpperCase().match(/.{1,4}/g).join(" ");

            actions.className = "flex flex-wrap gap-2 mt-2";

            if (false === lockedIds.has(certificate.userKeyId)) {
                actions.append(this.#button(this.messagesValue.smimeExport, "exportSmime", { id: certificate.id }));
            }

            actions.append(this.#button(this.messagesValue.smimeRemove, "removeSmime", { id: certificate.id }));
            row.append(addresses, meta, fingerprint, actions);

            return row;
        }));
    }

    /**
     * The floor and the agreement, checked here because the server never sees
     * a passphrase to check them on. Characters, not UTF-16 units, as the
     * password form counts them.
     */
    #acceptable(input, repeat) {
        if (Array.from(input.value).length < this.minLengthValue) {
            input.setCustomValidity(this.messagesValue.tooShort);
            input.reportValidity();

            return false;
        }

        if (input.value !== repeat.value) {
            repeat.setCustomValidity(this.messagesValue.mismatch);
            repeat.reportValidity();

            return false;
        }

        return true;
    }

    #button(label, action, params) {
        const button = document.createElement("button");

        button.type = "button";
        button.className = "inline-flex items-center px-2.5 h-8 rounded-lg text-xs font-medium bg-raised hover:bg-hover text-ink border border-line transition-colors cursor-pointer disabled:opacity-60";
        button.textContent = label;
        button.setAttribute("data-action", `click->settings--encryption#${action}`);

        for (const [name, value] of Object.entries(params)) {
            button.setAttribute(`data-settings--encryption-${name}-param`, String(value));
        }

        return button;
    }

    #icon(classes, extra = "") {
        const icon = document.createElement("i");

        icon.className = `${classes} ${extra}`;
        icon.setAttribute("aria-hidden", "true");

        return icon;
    }

    /**
     * Runs one action with its button held, then redraws — every keyring
     * write answers with the whole keyring, so whatever the action did to
     * rows it did not name shows up too.
     */
    async #busy(button, work) {
        if (button instanceof HTMLButtonElement) {
            button.disabled = true;
        }

        try {
            await work();
        } catch (error) {
            console.warn("Keyring:", error);
            showToast(this.messagesValue.failed, { type: "error" });
        } finally {
            if (button instanceof HTMLButtonElement) {
                button.disabled = false;
            }

            this.#render();
        }
    }

    #say(target, message, isError) {
        target.textContent = message;
        target.className = `mt-2 text-xs ${isError ? "text-danger" : "text-emerald-600"}`;
        target.hidden = false;
    }

    #date(iso) {
        return new Intl.DateTimeFormat(this.localeValue || undefined, { dateStyle: "medium" }).format(new Date(iso));
    }

    #download(name, content, type = "text/plain") {
        const url = URL.createObjectURL(new Blob([content], { type }));
        const link = document.createElement("a");

        link.href = url;
        link.download = name;
        document.body.append(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
}
