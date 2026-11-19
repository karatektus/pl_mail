/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";

/**
 * An account's colour menu in Settings → Accounts (#638).
 *
 * A colour is chosen by looking at it, so choosing previews and Save commits.
 * Picking a swatch, the colour well or a hex code repaints every mark of this
 * account on the page at once (the menu's button, the sidebar dot), found by
 * `data-account-dot`, and Cancel, Escape or a click elsewhere puts them back.
 * Save posts the form; the list streamed back paints the stored colour for real.
 *
 * The custom swatch is a radio of the same group as the palette whose value is
 * the hex code itself, kept in step with the colour well and the hex field, so
 * the form posts one `colour` field either way and the route tells an index
 * from a hex value.
 *
 * ## The contrast rows
 *
 * The reason for picking a colour here is usually that the palette's were hard
 * to see, so the menu shows how this one stands out against:
 *
 *   - the theme in use, read from the page (`--rgb-surface`) and not assumed,
 *     because themes are the user's and a cream or nord one is neither of the
 *     other two;
 *   - the plain light and dark backgrounds, because a custom colour is one value
 *     on every theme (Account::$customColor says why), and the other mode is
 *     where it will be seen next.
 *
 * The current row is dropped when the theme's background IS the light or dark
 * one, rather than saying the same thing twice.
 *
 * Below 3:1, WCAG's floor for marks that are not text, a row says the colour is
 * faint there, and Save still works: the user is the one judging what they can
 * see, and the issue that asked for this came from someone the palette's
 * "accessible" colours failed.
 *
 * Values:
 *   account — the account's id, to find its marks on the page
 */
const LIGHT = [255, 255, 255];
const DARK = [17, 24, 39];
const WHEEL = "conic-gradient(#ef4444, #eab308, #22c55e, #06b6d4, #3b82f6, #a855f7, #ef4444)";

export default class extends Controller {
    static targets = [
        "form", "customRadio", "customSwatch", "customIcon", "panel",
        "well", "wellFace", "hex", "invalid", "row", "save",
    ];
    static values = { account: Number };

    connect() {
        this._committed = false;
        this._initial = {
            swatch: this.customSwatchTarget.getAttribute("style") ?? "",
            icon: this.customIconTarget.className,
            iconStyle: this.customIconTarget.getAttribute("style") ?? "",
        };
        this._sync({ preview: false });
    }

    /** A radio of the group was picked. */
    choose() {
        const custom = this.customRadioTarget.checked;

        this.panelTarget.hidden = !custom;

        if (custom) {
            this.hexTarget.focus();
            this.hexTarget.select();
        }

        this._relayout();
        this._sync();
    }

    pickWell() {
        this.hexTarget.value = this.wellTarget.value;
        this._setCustom(this.wellTarget.value);
    }

    typeHex() {
        const value = this._normalise(this.hexTarget.value);

        if (null !== value) {
            this.wellTarget.value = value;
            this._setCustom(value);
            return;
        }

        this._sync();
    }

    /** Fills in the `#` that most palette sites leave out. */
    complete() {
        const value = this._normalise(this.hexTarget.value);

        if (null !== value) {
            this.hexTarget.value = value;
        }
    }

    commit() {
        this._committed = true;
    }

    /**
     * Cancel is a reset button (which also closes the menu): the form is back
     * as it was rendered, and so are the marks it was previewing on.
     */
    reverted() {
        // The reset event fires before the fields take their old values.
        requestAnimationFrame(() => {
            // A radio's value is its attribute, so reset does not bring the
            // rendered hex back to it; the hex field's own reset does.
            this.customRadioTarget.value = this.hexTarget.value;
            this.customSwatchTarget.setAttribute("style", this._initial.swatch);
            this.customIconTarget.className = this._initial.icon;
            this.customIconTarget.setAttribute("style", this._initial.iconStyle);
            this.panelTarget.hidden = !this.customRadioTarget.checked;
            this._sync({ preview: false });
            this._restoreMarks();
        });
    }

    /** The menu closed without Save: Escape or a click elsewhere. */
    restore() {
        if (this._committed) {
            return;
        }

        this.formTarget.reset();
    }

    // ── internals ───────────────────────────────────────────────────────────

    _setCustom(value) {
        this.customRadioTarget.value = value;
        this.customRadioTarget.checked = true;

        if (this.panelTarget.hidden) {
            this.panelTarget.hidden = false;
            this._relayout();
        }

        this._sync();
    }

    /** Everything that follows from the current choice. */
    _sync({ preview = true } = {}) {
        const hex = this._normalise(this.hexTarget.value);
        const custom = this.customRadioTarget.checked;
        const invalid = custom && null === hex;

        this.invalidTarget.hidden = !invalid;
        this.hexTarget.setAttribute("aria-invalid", invalid ? "true" : "false");
        this.saveTarget.disabled = invalid;

        if (null !== hex) {
            this.wellFaceTarget.style.backgroundColor = hex;
            this._contrast(hex);
        }

        // The ninth swatch wears the custom colour, with a tick that reads on
        // it, only while it is the choice. Otherwise it is the colour wheel
        // with a plus, whatever was typed: a swatch still showing the last
        // custom colour after a preset was picked read as a second choice,
        // and its tick said so.
        if (custom && null !== hex) {
            this.customSwatchTarget.style.background = hex;
            this.customIconTarget.classList.replace("fa-plus", "fa-check");
            this.customIconTarget.classList.remove("text-white");
            this.customIconTarget.style.color = 0.4 < this._luminance(this._channels(hex)) ? "#18181b" : "#ffffff";
        } else if (false === custom) {
            this.customSwatchTarget.style.background = WHEEL;
            this.customIconTarget.classList.replace("fa-check", "fa-plus");
            this.customIconTarget.classList.add("text-white");
            this.customIconTarget.style.color = "";
        }

        if (false === preview || invalid) {
            return;
        }

        const chosen = this.formTarget.querySelector('input[name="colour"]:checked');

        if (null === chosen) {
            return;
        }

        this._previewMarks(custom
            ? hex
            : getComputedStyle(chosen.parentElement.querySelector("[data-swatch]")).backgroundColor);
    }

    _contrast(hex) {
        const current = this._surface();
        const same = (a, b) => a.every((c, i) => c === b[i]);
        const backgrounds = { current, light: LIGHT, dark: DARK };
        const colour = this._channels(hex);

        for (const row of this.rowTargets) {
            const mode = row.dataset.mode;
            const background = backgrounds[mode];

            if ("current" === mode) {
                row.hidden = same(current, LIGHT) || same(current, DARK);
            }

            const ratio = this._ratio(colour, background);

            row.querySelector("[data-chip]").style.backgroundColor = `rgb(${background.join(" ")})`;
            row.querySelector("[data-chip-dot]").style.backgroundColor = hex;
            row.querySelector("[data-ratio]").textContent = `${ratio.toFixed(1)}:1`;
            row.querySelector("[data-ok]").hidden = 3 > ratio;
            row.querySelector("[data-low]").hidden = 3 <= ratio;
        }
    }

    /** Every mark of this account on the page, the sidebar's included. */
    _marks() {
        return document.querySelectorAll(`[data-account-dot="${this.accountValue}"]`);
    }

    _previewMarks(colour) {
        for (const mark of this._marks()) {
            if (undefined === mark.dataset.accountDotWas) {
                mark.dataset.accountDotWas = mark.style.backgroundColor;
            }

            mark.style.backgroundColor = colour;
        }
    }

    _restoreMarks() {
        for (const mark of this._marks()) {
            if (undefined !== mark.dataset.accountDotWas) {
                mark.style.backgroundColor = mark.dataset.accountDotWas;
                delete mark.dataset.accountDotWas;
            }
        }
    }

    /** The menu sits in the top layer, placed from its size; it changed. */
    _relayout() {
        window.dispatchEvent(new Event("resize"));
    }

    _normalise(raw) {
        const value = raw.trim().replace(/^#?/, "#");

        return /^#[0-9a-fA-F]{6}$/.test(value) ? value.toLowerCase() : null;
    }

    _channels(hex) {
        const n = parseInt(hex.slice(1), 16);

        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }

    /** The theme's background as [r, g, b]; the light one if the page does not say. */
    _surface() {
        const channels = getComputedStyle(this.element)
            .getPropertyValue("--rgb-surface")
            .trim()
            .split(/[\s,]+/)
            .map(Number);

        return 3 === channels.length && channels.every((c) => Number.isFinite(c)) ? channels : LIGHT;
    }

    _ratio(a, b) {
        const x = this._luminance(a);
        const y = this._luminance(b);

        return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
    }

    /** WCAG 2 relative luminance. */
    _luminance(rgb) {
        const [r, g, b] = rgb.map((v) => {
            const c = v / 255;

            return 0.03928 >= c ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        });

        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    }
}
