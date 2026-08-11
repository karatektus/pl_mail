import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        updateUrl: String,
        uploadUrl: String,
        importUrl: String,
        resetUrl: String,
    };

    static targets = ['paneAlpha', 'paneBlur', 'radius', 'scrimAlpha', 'accent', 'theme', 'importInput', 'uploadInput',
        'inkColor', 'inkColorField', 'inkDefault', 'inkDerived', 'inkMuted', 'inkMutedField', 'inkFaint', 'inkFaintField',
        'mainTint', 'mainTintField', 'mainTintDefault', 'mainAlpha', 'mainAlphaField', 'mainAlphaMatch'];

    connect() {
        this.root = document.documentElement;
        this.pending = null;

        if (this.hasInkColorFieldTarget && this.inkColorFieldTarget.value !== '') {
            this.applyInk();
        }
    }

    disconnect() {
        if (this.pending) {
            clearTimeout(this.pending);
        }
    }

    /* ── Live preview ─────────────────────────────────────────────────────── */

    slide(event) {
        const variable = event.currentTarget.dataset.cssVariable;
        const suffix = event.currentTarget.dataset.cssSuffix || '';
        this.root.style.setProperty(variable, `${event.currentTarget.value}${suffix}`);
        this.queue();
    }

    pickTheme(event) {
        const theme = event.currentTarget.dataset.theme;

        this.themeTarget.value = theme;

        this.root.dataset.theme = theme;
        this.root.classList.toggle('dark', this.resolvesDark(event.currentTarget));

        this.element.querySelectorAll('[data-theme]').forEach((button) => {
            button.classList.toggle('ring-2', button.dataset.theme === theme);
            button.classList.toggle('ring-accent', button.dataset.theme === theme);
        });

        // A theme may seed knobs too, the way a layout does. The accent has to
        // come through here rather than from the stylesheet: it is written
        // inline on <html> from the user's setting, so a [data-theme] rule for
        // it would never win.
        //
        // Cleared first, then applied. Switching a theme must not be able to
        // leave the previous one's inline values on <html>: a key that theme A
        // seeds and theme B does not would otherwise survive the switch, paint
        // over B's stylesheet block, and get saved as B's setting on the way
        // out. Every theme seeds the same keys today — Theme::defaults() is one
        // expression for all of them, and a test holds it that way — so this
        // clears nothing in practice. It is here so that the day one of them
        // grows a key the others lack, the switch still ends where a fresh load
        // of that theme would.
        const defaults = JSON.parse(event.currentTarget.dataset.themeDefaults || '{}');

        this.themeDefaultKeys()
            .filter((key) => Object.hasOwn(defaults, key) === false)
            .forEach((key) => this.clearDefault(key));

        this.applyDefaults(defaults);

        this.queue();
    }

    /*
     * Whether this theme paints dark right now.
     *
     * `system` has no dark of its own — it is whatever the OS says, which is
     * the question app.html.twig's no-flash script asks on every load. Asking
     * it here too is what makes a live switch land where a reload would.
     * data-dark is Theme::isDark(), and System is not dark by that measure, so
     * picking System on a dark desktop used to drop the class and paint the
     * light palette until the next navigation put it back.
     */
    resolvesDark(button) {
        if (button.dataset.theme === 'system') {
            return window.matchMedia('(prefers-color-scheme: dark)').matches;
        }

        return button.dataset.dark === '1';
    }

    /** Every key any theme seeds — the set a switch has to account for. */
    themeDefaultKeys() {
        const keys = new Set();

        this.element.querySelectorAll('[data-theme-defaults]').forEach((button) => {
            Object.keys(JSON.parse(button.dataset.themeDefaults || '{}')).forEach((key) => keys.add(key));
        });

        return [...keys];
    }

    /*
     * Drop the inline preview for one seeded key, so the stylesheet's own
     * value for the theme now on <html> takes over. That is only a safe answer
     * because every [data-theme] block declares the whole palette: there is no
     * key whose removal falls through to some other theme's value.
     */
    clearDefault(field) {
        if (field === 'accent') {
            this.root.style.removeProperty('--rgb-accent');
            this.root.style.removeProperty('--rgb-accent-ink');

            return;
        }

        if (field === 'density') {
            this.root.style.removeProperty('--density-row-y');
            this.root.style.removeProperty('--density-gap');

            return;
        }

        const input = this.element.querySelector(`[data-settings--appearance-field="${field}"]`);

        if (input && input.dataset.cssVariable) {
            this.root.style.removeProperty(input.dataset.cssVariable);
        }
    }

    pickLayout(event) {
        const option = event.currentTarget.selectedOptions[0];

        // Exactly one layout class may be on <html> at a time, so clear every
        // known one before adding this layout's (the default layout has none).
        event.currentTarget.querySelectorAll('[data-layout-class]').forEach((candidate) => {
            if (candidate.dataset.layoutClass !== '') {
                this.root.classList.remove(candidate.dataset.layoutClass);
            }
        });

        if (option.dataset.layoutClass !== '') {
            this.root.classList.add(option.dataset.layoutClass);
        }

        this.applyDefaults(JSON.parse(option.dataset.layoutDefaults));
        this.queue(0);
    }

    /*
     * Seed the knobs a layout ships with. The controls are driven rather than
     * bypassed: the sliders visibly move, and save() then picks the values up
     * from the DOM like any manual change. Each slider already carries the CSS
     * variable it maps to, so nothing is hardcoded here.
     */
    applyDefaults(defaults) {
        Object.entries(defaults).forEach(([field, value]) => {
            if (field === 'density') {
                const radio = this.element.querySelector(
                    `[data-settings--appearance-field="density"][value="${value}"]`,
                );

                if (radio) {
                    radio.checked = true;
                    this.root.style.setProperty('--density-row-y', radio.dataset.rowY);
                    this.root.style.setProperty('--density-gap', radio.dataset.gap);
                }

                return;
            }

            const input = this.element.querySelector(`[data-settings--appearance-field="${field}"]`);

            if (!input) {
                return;
            }

            input.value = value;

            // The accent is two derived variables rather than one literal, so
            // the generic data-css-variable path below cannot preview it.
            if (field === 'accent') {
                this.root.style.setProperty('--rgb-accent', this.channels(value));
                this.root.style.setProperty('--rgb-accent-ink', this.contrast(value));

                return;
            }

            if (input.dataset.cssVariable) {
                this.root.style.setProperty(
                    input.dataset.cssVariable,
                    `${value}${input.dataset.cssSuffix || ''}`,
                );
            }
        });
    }

    pickAccent(event) {
        const hex = event.currentTarget.value || event.currentTarget.dataset.accent;
        this.accentTarget.value = hex;
        this.root.style.setProperty('--rgb-accent', this.channels(hex));
        this.root.style.setProperty('--rgb-accent-ink', this.contrast(hex));
        this.queue();
    }

    pickDensity(event) {
        const rowY = event.currentTarget.dataset.rowY;
        const gap = event.currentTarget.dataset.gap;
        this.root.style.setProperty('--density-row-y', rowY);
        this.root.style.setProperty('--density-gap', gap);
        this.queue();
    }

    pickBackground(event) {
        if (event.currentTarget.getAttribute('data-settings--appearance-field') === 'backgroundPreset') {
            const kindInput = this.element.querySelector('[data-settings--appearance-field="backgroundKind"][value="preset"]');

            if (kindInput) {
                kindInput.checked = true;
            }
        }

        this.queue(0);
    }

    /* ── Persistence ──────────────────────────────────────────────────────── */

    queue(delay = 400) {
        if (this.pending) {
            clearTimeout(this.pending);
        }

        this.pending = setTimeout(() => this.save(), delay);
    }

    async save() {
        const payload = {};

        this.element.querySelectorAll('[data-settings--appearance-field]').forEach((input) => {
            if (input.type === 'radio' && input.checked === false) {
                return;
            }

            payload[input.getAttribute('data-settings--appearance-field')] = input.value;
        });

        try {
            await fetch(this.updateUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            this.remember();
        } catch (error) {
            console.error('Appearance save failed', error);
        }
    }

    /*
     * Mirror of the snapshot the layout writes on every authenticated render.
     * This page never reloads, so without it the logged-out screens would keep
     * showing the appearance as it was when the page was opened.
     */
    remember() {
        // `dark` under a system theme and `sidebar-rail` are applied at runtime
        // from their own state, so they must not be baked into the snapshot.
        const classes = [...this.root.classList].filter((name) => (
            name !== 'sidebar-rail' && (name !== 'dark' || this.root.dataset.theme !== 'system')
        ));

        try {
            localStorage.setItem('plmail:appearance', JSON.stringify({
                class: classes.join(' '),
                theme: this.root.dataset.theme,
                style: this.root.getAttribute('style') || '',
            }));
        } catch (error) {
            /* Private mode / storage full — the theme just stays server-side. */
        }
    }

    async upload() {
        const file = this.uploadInputTarget.files[0];

        if (!file) {
            return;
        }

        const data = new FormData();
        data.append('background', file);

        const response = await fetch(this.uploadUrlValue, { method: 'POST', body: data });
        const result = await response.json();

        if (result.ok === true) {
            this.root.style.setProperty('--app-bg', `url("${result.url}")`);
        }
    }

    async importFile() {
        const file = this.importInputTarget.files[0];

        if (!file) {
            return;
        }

        const text = await file.text();

        const response = await fetch(this.importUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: text,
        });

        const result = await response.json();

        if (result.ok === true) {
            window.location.reload();
        }
    }

    async reset() {
        await fetch(this.resetUrlValue, { method: 'POST' });
        window.location.reload();
    }

    channels(hex) {
        const clean = hex.replace('#', '');
        const r = parseInt(clean.substring(0, 2), 16);
        const g = parseInt(clean.substring(2, 4), 16);
        const b = parseInt(clean.substring(4, 6), 16);

        return `${r} ${g} ${b}`;
    }

    /**
     * The ink to write ON the accent, by measurement.
     *
     * This is AppearanceRenderer::contrastChannels() in JavaScript, and it has
     * to stay that — the picker paints the choice live and the server paints it
     * on the next load, so any disagreement between the two shows up as the
     * accent's text changing colour on reload. (The e2e theme-switch parity
     * spec is what catches that.)
     *
     * Luminance is computed on gamma-EXPANDED channels, which is what WCAG
     * defines and what the old weighted-raw-channel test was not; it only
     * crossed its 0.6 threshold for very light accents, so a mid-tone pink got
     * white text at 2.34:1 against a 4.5:1 requirement. Both candidate inks are
     * scored and the better wins, so there is no threshold left to mistune.
     * #18181B is the app's dark ink everywhere else and stays the dark choice
     * unless it cannot reach 4.5:1, in which case pure black does — see the PHP
     * for why that band exists.
     */
    contrast(hex) {
        const accent = this.relativeLuminance(this.channels(hex).split(' ').map(Number));

        const house = [24, 24, 27];
        const light = [255, 255, 255];
        const black = [0, 0, 0];

        const onHouse = this.contrastRatio(accent, this.relativeLuminance(house));
        const onLight = this.contrastRatio(accent, this.relativeLuminance(light));
        const onBlack = this.contrastRatio(accent, this.relativeLuminance(black));

        if (onLight > onHouse && onLight > onBlack) {
            return light.join(' ');
        }

        return (onHouse >= 4.5 ? house : black).join(' ');
    }

    relativeLuminance([r, g, b]) {
        const expand = (channel) => {
            const c = channel / 255;

            return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        };

        return 0.2126 * expand(r) + 0.7152 * expand(g) + 0.0722 * expand(b);
    }

    contrastRatio(a, b) {
        return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    }

    /* ── Text ─────────────────────────────────────────────────────────────── */

    pickInk(event) {
        this.inkColorFieldTarget.value = event.currentTarget.value;
        this.inkDefaultTarget.checked = false;
        this.inkDerivedTarget.classList.remove('opacity-40', 'pointer-events-none');
        this.applyInk();
        this.queue();
    }

    pickInkMuted(event) {
        this.inkMutedFieldTarget.value = event.currentTarget.value;
        this.applyInk();
        this.queue();
    }

    pickInkFaint(event) {
        this.inkFaintFieldTarget.value = event.currentTarget.value;
        this.applyInk();
        this.queue();
    }

    resetInkDerived() {
        this.inkMutedFieldTarget.value = '';
        this.inkFaintFieldTarget.value = '';
        this.applyInk();
        this.queue();
    }

    toggleInkDefault(event) {
        if (event.currentTarget.checked === true) {
            this.inkColorFieldTarget.value = '';
            this.inkMutedFieldTarget.value = '';
            this.inkFaintFieldTarget.value = '';
            this.root.style.removeProperty('--rgb-ink');
            this.root.style.removeProperty('--rgb-ink-soft');
            this.root.style.removeProperty('--rgb-ink-muted');
            this.root.style.removeProperty('--rgb-ink-faint');
            this.inkDerivedTarget.classList.add('opacity-40', 'pointer-events-none');
        } else {
            this.inkColorFieldTarget.value = this.inkColorTarget.value;
            this.inkDerivedTarget.classList.remove('opacity-40', 'pointer-events-none');
            this.applyInk();
        }
        this.queue();
    }

    applyInk() {
        const base = this.inkColorFieldTarget.value || this.inkColorTarget.value;
        const muted = this.inkMutedFieldTarget.value;
        const faint = this.inkFaintFieldTarget.value;

        this.root.style.setProperty('--rgb-ink', this.channels(base));
        this.root.style.setProperty('--rgb-ink-soft', this.blendToGrey(base, 0.22));
        this.root.style.setProperty('--rgb-ink-muted', muted !== '' ? this.channels(muted) : this.blendToGrey(base, 0.40));
        this.root.style.setProperty('--rgb-ink-faint', faint !== '' ? this.channels(faint) : this.blendToGrey(base, 0.62));

        if (muted === '') {
            this.inkMutedTarget.value = this.rgbToHex(this.blendToGrey(base, 0.40));
        }
        if (faint === '') {
            this.inkFaintTarget.value = this.rgbToHex(this.blendToGrey(base, 0.62));
        }
    }

    /* ── Main pane ────────────────────────────────────────────────────────── */

    pickMainTint(event) {
        this.mainTintFieldTarget.value = event.currentTarget.value;
        this.mainTintDefaultTarget.checked = false;
        this.root.style.setProperty('--rgb-main', this.channels(event.currentTarget.value));
        this.queue();
    }

    toggleMainTintDefault(event) {
        if (event.currentTarget.checked === true) {
            this.mainTintFieldTarget.value = '';
            this.root.style.removeProperty('--rgb-main');
        } else {
            this.mainTintFieldTarget.value = this.mainTintTarget.value;
            this.root.style.setProperty('--rgb-main', this.channels(this.mainTintTarget.value));
        }
        this.queue();
    }

    slideMainAlpha(event) {
        this.mainAlphaFieldTarget.value = event.currentTarget.value;
        this.mainAlphaMatchTarget.checked = false;
        this.root.style.setProperty('--main-alpha', event.currentTarget.value);
        this.queue();
    }

    toggleMainAlphaMatch(event) {
        if (event.currentTarget.checked === true) {
            this.mainAlphaFieldTarget.value = '';
            this.root.style.removeProperty('--main-alpha');
            this.mainAlphaTarget.classList.add('opacity-40', 'pointer-events-none');
        } else {
            this.mainAlphaFieldTarget.value = this.mainAlphaTarget.value;
            this.root.style.setProperty('--main-alpha', this.mainAlphaTarget.value);
            this.mainAlphaTarget.classList.remove('opacity-40', 'pointer-events-none');
        }
        this.queue();
    }

    /* ── Colour helpers ───────────────────────────────────────────────────── */

    blendToGrey(hex, factor) {
        const h = hex.replace('#', '');
        const mix = (c) => Math.round(c + factor * (128 - c));
        return `${mix(parseInt(h.slice(0, 2), 16))} ${mix(parseInt(h.slice(2, 4), 16))} ${mix(parseInt(h.slice(4, 6), 16))}`;
    }

    rgbToHex(triplet) {
        return '#' + triplet.split(' ').map((n) => parseInt(n, 10).toString(16).padStart(2, '0')).join('');
    }
}
