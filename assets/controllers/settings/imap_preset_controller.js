import { Controller } from "@hotwired/stimulus";

/**
 * Prefills an account's servers from a shipped provider preset, for whichever
 * incoming protocol is chosen: nearly every preset is an IMAP server, and a
 * provider whose POP3 or JMAP server is known carries that too
 * (config/mail_presets.yaml). plMail's own preset is JMAP alone, and has no
 * host — every instance has its own — so it fills in all but that.
 *
 * Fields that mean nothing for the protocol stay out of the way: under JMAP
 * the encryption select (it is HTTPS), and the session URL until it is asked
 * for, since nearly every server answers at the well-known path.
 *
 * It also shapes the outgoing half to the protocol. A JMAP server sends mail
 * itself (JmapApiSender), so under JMAP the SMTP fields fold away behind an
 * offer, for the server that does not; SMTP filled in already stays in view.
 *
 * Fields are resolved by name suffix (account[imapHost] → imapHost) rather
 * than by Stimulus targets, so the controller does not depend on the form
 * theme passing data attributes through to the widget.
 *
 * The preset table is embedded on the select element itself (data-presets) —
 * the whole table is a couple of kilobytes, so no round-trip is needed.
 */
export default class extends Controller {
    static targets = [
        "note", "incomingLabel", "pop3Options", "jmapOptions",
        "outgoingLabel", "smtpFields", "smtpOffer", "jmapOutgoing",
        "incomingEncryption", "sessionUrl", "sessionUrlOffer",
    ];

    /**
     * Where each incoming protocol starts: its SSL port, and the shape of a
     * host name to hint at. The heading is not here: it is translated, so the
     * template hands every wording to the heading element as
     * data-heading-imap / data-heading-pop3 / data-heading-jmap.
     *
     * JMAP's port is HTTPS's: the session is found at a well-known path on
     * the web server, and the encryption select is left alone because there
     * is no plaintext JMAP to choose.
     */
    static PROTOCOLS = {
        imap: { port: "993", host: "imap.example.com" },
        pop3: { port: "995", host: "pop.example.com" },
        jmap: { port: "443", host: "mail.example.com" },
    };

    /** The data-heading-* attribute each protocol's heading is read from. */
    static HEADINGS = { imap: "headingImap", pop3: "headingPop3", jmap: "headingJmap" };

    connect() {
        this.presets = this._readPresets();
        this.smtpWanted = false;
        this.sessionUrlWanted = false;
        this.protocolChanged();
    }

    /**
     * The incoming-protocol radio was changed.
     *
     * Three things follow, and all three are the same fact stated three ways:
     * the section heading names the protocol, the port follows,
     * and the POP3 delete option and the JMAP session options appear only
     * where they mean something.
     * Nothing already typed is cleared — switching back and forth should not
     * cost the user their host name.
     */
    protocolChanged() {
        const protocol = this._currentProtocol();

        if (this.hasIncomingLabelTarget) {
            const heading = this.incomingLabelTarget.dataset[this.constructor.HEADINGS[protocol] ?? "headingImap"];

            if (heading) {
                this.incomingLabelTarget.textContent = heading;
            }
        }

        const defaults = this.constructor.PROTOCOLS;
        const chosen = defaults[protocol] ?? defaults.imap;
        const port = this._field("imapPort");

        if (port !== null) {
            port.placeholder = chosen.port;

            // A blank form arrives with 993 filled in. Still holding the
            // other protocol's default, the port follows the switch; a port
            // somebody typed is theirs and stays.
            const isADefault = Object.values(defaults).some((entry) => entry.port === port.value);

            if (port.value === "" || isADefault) {
                port.value = chosen.port;
            }
        }

        const host = this._field("imapHost");

        if (host !== null) {
            host.placeholder = chosen.host;
        }

        if (this.hasPop3OptionsTarget) {
            this.pop3OptionsTarget.classList.toggle("hidden", protocol !== "pop3");
        }

        if (this.hasJmapOptionsTarget) {
            this.jmapOptionsTarget.classList.toggle("hidden", protocol !== "jmap");
        }

        if (this.hasIncomingEncryptionTarget) {
            this.incomingEncryptionTarget.classList.toggle("hidden", protocol === "jmap");
        }

        if (this.filling !== true) {
            this._refill(protocol);
        }

        this._shapeOutgoing(protocol);
        this._shapeSessionUrl();
    }

    /** The JMAP session is not at the well-known path. */
    useSessionUrl() {
        this.sessionUrlWanted = true;
        this._shapeSessionUrl();
        this._field("jmapSessionUrl")?.focus();
    }

    /** The session URL field, where it is asked for or has a value. */
    _shapeSessionUrl() {
        const shown = this.sessionUrlWanted || this._value("jmapSessionUrl") !== "";

        if (this.hasSessionUrlTarget) {
            this.sessionUrlTarget.classList.toggle("hidden", shown === false);
        }

        if (this.hasSessionUrlOfferTarget) {
            this.sessionUrlOfferTarget.classList.toggle("hidden", shown);
        }
    }

    /** "Send through SMTP instead": the server does not send over JMAP. */
    useSmtp() {
        this.smtpWanted = true;
        this._shapeOutgoing(this._currentProtocol());
        this._field("smtpHost")?.focus();
    }

    /** Which radio is checked, defaulting to IMAP like the entity does. */
    _currentProtocol() {
        const checked = this.element.querySelector('[name$="[incomingProtocol]"]:checked');

        return checked === null ? "imap" : checked.value;
    }

    /** Explicit choice from the dropdown — always wins. */
    apply() {
        const select = this._field("preset");

        if (select === null) {
            return;
        }

        const preset = this.presets[select.value];

        if (preset === undefined) {
            this._renderNote(null);
            return;
        }

        this._fill(preset);
    }

    /** Best-effort detection from the typed address. Never clobbers input. */
    detect() {
        const select = this._field("preset");

        if (select === null || select.value !== "") {
            return;
        }

        if (this._value("imapHost") !== "") {
            return;
        }

        const address = this._value("username");
        const at = address.lastIndexOf("@");

        if (at === -1) {
            return;
        }

        const domain = address.slice(at + 1).toLowerCase();
        const key = Object.keys(this.presets).find(
            (candidate) => this.presets[candidate].domains.includes(domain),
        );

        if (key === undefined) {
            return;
        }

        this._select(select, key);
    }

    // ── Private ───────────────────────────────────────────────────────────

    _readPresets() {
        const select = this._field("preset");

        if (select === null || select.dataset.presets === undefined) {
            console.error("imap-preset: preset select not found or carries no payload");
            return {};
        }

        try {
            return JSON.parse(select.dataset.presets);
        } catch (error) {
            console.error("imap-preset: malformed preset payload", error);
            return {};
        }
    }

    _field(name) {
        return this.element.querySelector(`[name$="[${name}]"]`);
    }

    _value(name) {
        const field = this._field(name);

        if (field === null) {
            return "";
        }

        return field.value.trim();
    }

    /** Sets the dropdown, going through Tom Select when it is active. */
    _select(select, key) {
        if (select.tomselect !== undefined) {
            // setValue("") leaves the old choice standing; clearing is its
            // own call. Not silent, so the change still reaches apply().
            if (key === "") {
                select.tomselect.clear(false);
            } else {
                select.tomselect.setValue(key, false);
            }

            return;
        }

        select.value = key;
        select.dispatchEvent(new Event("change", { bubbles: true }));
    }

    /**
     * An explicit choice: the chosen protocol's server where the preset has
     * one, and otherwise IMAP, or JMAP for a preset that is JMAP alone — a
     * POP3 session pointed at an IMAP host would only fail at the connection
     * test.
     */
    _fill(preset) {
        let protocol = this._currentProtocol();

        if (this._block(preset, protocol) === null) {
            protocol = ["imap", "jmap", "pop3"].find((name) => this._block(preset, name) !== null);
            this._choose(protocol);
        }

        this._fillFor(preset, protocol, true);
    }

    /**
     * The protocol was switched with a preset chosen: the preset's server for
     * the new one, so Fastmail's IMAP host is not left in the JMAP field. Only
     * while the host is still one the preset put there — a host somebody typed
     * is theirs. A preset without the new protocol is let go of, and its host
     * with it, rather than switching the radio back under the user's finger.
     */
    _refill(protocol) {
        const select = this._field("preset");
        const preset = select === null ? undefined : this.presets[select.value];

        if (preset === undefined) {
            return;
        }

        const host = this._value("imapHost");
        const presetsHost = host !== "" && this._isPresetHost(preset, host);

        if (this._block(preset, protocol) === null) {
            if (presetsHost) {
                this._set("imapHost", "");
            }

            this._select(select, "");
            return;
        }

        if (host === "" || presetsHost) {
            this._fillFor(preset, protocol, false);
        }
    }

    /** Whether a host is one this preset would have put in the field. */
    _isPresetHost(preset, host) {
        return ["imap", "pop3", "jmap"].some((name) => this._block(preset, name)?.host === host);
    }

    /**
     * Writes a preset's servers for one protocol. Explicit (the dropdown)
     * overwrites SMTP; a protocol switch only fills it where it is empty.
     * Under JMAP the preset's SMTP is taken out again where it is still the
     * preset's: the server sends, and SMTP left filled would keep the
     * fallback unfolded for nothing.
     */
    _fillFor(preset, protocol, explicit) {
        const incoming = this._block(preset, protocol);

        if (incoming.host) {
            this._set("imapHost", incoming.host);
        } else if (this._isAnyPresetHost(this._value("imapHost"))) {
            // A server with no one host: the field is the user's to fill, and
            // another provider's host left in it would only mislead.
            this._set("imapHost", "");
        }

        if (protocol === "jmap") {
            this._set("imapPort", this.constructor.PROTOCOLS.jmap.port);
            this._set("jmapSessionUrl", incoming.sessionUrl ?? "");
            this._setChecked("jmapTokenAuth", incoming.tokenAuth === true);

            if (this._isAnyPresetSmtpHost(this._value("smtpHost"))) {
                this._set("smtpHost", "");
                this._set("smtpPort", "");
            }
        } else {
            this._set("imapPort", incoming.port);
            this._set("imapEncryption", incoming.encryption);

            if (preset.smtp && (explicit || this._value("smtpHost") === "")) {
                this._set("smtpHost", preset.smtp.host);
                this._set("smtpPort", preset.smtp.port);
                this._set("smtpEncryption", preset.smtp.encryption);
            }
        }

        if (incoming.host === null || incoming.host === undefined) {
            this._field("imapHost")?.focus();
        }

        this._renderNote(protocol === "jmap" ? (incoming.note ?? preset.note) : preset.note);
        this._shapeOutgoing(protocol);
        this._shapeSessionUrl();
    }

    /** A preset's server for one protocol, or null where it has none. */
    _block(preset, protocol) {
        return preset[protocol] ?? null;
    }

    /** Whether any preset would have put this host in the incoming field. */
    _isAnyPresetHost(host) {
        return host !== "" && Object.values(this.presets).some((preset) => this._isPresetHost(preset, host));
    }

    /** Whether any preset would have put this host in the SMTP field. */
    _isAnyPresetSmtpHost(host) {
        return host !== "" && Object.values(this.presets).some((preset) => preset.smtp?.host === host);
    }

    /** Ticks a protocol radio without refilling from the preset again. */
    _choose(protocol) {
        const radio = this.element.querySelector(`[name$="[incomingProtocol]"][value="${protocol}"]`);

        if (radio === null || radio.checked) {
            return;
        }

        radio.checked = true;
        this.filling = true;

        try {
            this.protocolChanged();
        } finally {
            this.filling = false;
        }
    }

    /**
     * The outgoing heading, and under JMAP the SMTP fields folded behind an
     * offer — unless SMTP is filled in already, or was asked for.
     */
    _shapeOutgoing(protocol) {
        const jmap = protocol === "jmap";
        const smtpShown = jmap === false || this.smtpWanted || this._value("smtpHost") !== "";

        if (this.hasOutgoingLabelTarget) {
            const heading = this.outgoingLabelTarget.dataset[jmap ? "headingJmap" : "headingSmtp"];

            if (heading) {
                this.outgoingLabelTarget.textContent = heading;
            }
        }

        if (this.hasJmapOutgoingTarget) {
            this.jmapOutgoingTarget.classList.toggle("hidden", jmap === false);
        }

        if (this.hasSmtpFieldsTarget) {
            this.smtpFieldsTarget.classList.toggle("hidden", smtpShown === false);
        }

        if (this.hasSmtpOfferTarget) {
            this.smtpOfferTarget.classList.toggle("hidden", jmap === false || smtpShown);
        }
    }

    _setChecked(name, checked) {
        const field = this.element.querySelector(`input[type="checkbox"][name$="[${name}]"]`);

        if (field === null || field.checked === checked) {
            return;
        }

        field.checked = checked;
        field.dispatchEvent(new Event("change", { bubbles: true }));
    }

    _set(name, value) {
        const field = this._field(name);

        if (field === null) {
            console.warn(`imap-preset: no field matching [name$="[${name}]"]`);
            return;
        }

        field.value = value;
        field.dispatchEvent(new Event("input", { bubbles: true }));
        field.dispatchEvent(new Event("change", { bubbles: true }));
    }

    _renderNote(note) {
        if (this.hasNoteTarget === false) {
            return;
        }

        if (note === null || note === undefined || note === "") {
            this.noteTarget.classList.add("hidden");
            this.noteTarget.textContent = "";
            return;
        }

        this.noteTarget.textContent = note;
        this.noteTarget.classList.remove("hidden");
    }
}
