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
        'mainTint', 'mainTintField', 'mainTintDefault', 'mainAlpha', 'mainAlphaField', 'mainAlphaMatch',
        'backgroundSolid', 'backgroundSolidSwatch',
        'previewRegion', 'previewToggle', 'previewToggleLabel', 'previewToggleIcon'];

    connect() {
        this.root = document.documentElement;
        this.pending = null;

        if (this.hasInkColorFieldTarget && this.inkColorFieldTarget.value !== '') {
            this.applyInk();
        }
    }

    /* ── The preview, on a screen too narrow to hold it beside the controls ─
     *
     * Above @3xl the preview card is simply there and this never runs — the
     * button that calls it is `@3xl:hidden`. Below it, the preview card starts
     * collapsed and this is the only way to it.
     *
     * The `hidden` CLASS is what moves, and it moves by being REMOVED rather
     * than by having a `flex` switched on over the top of it. In the built
     * stylesheet `.hidden` is ordered after `.flex`, so adding `flex` to an
     * element that still carries `hidden` changes precisely nothing on screen
     * while every attribute says it worked — which is the most expensive kind
     * of bug this file can have, because it reads as JavaScript that did not
     * run. Both classes are toggled, so the element is `flex` when shown and
     * `hidden` when not, and the `@3xl:flex` the server rendered keeps the
     * desktop layout right in either state.
     *
     * There are TWO controls for this one state — the header's "Show preview"
     * and the pinned card's close — so the state is read off the REGION rather
     * than off whichever button was pressed. Two buttons each holding their own
     * idea of `aria-expanded` is how a disclosure comes to need pressing twice.
     *
     * Nothing is persisted. See the note on the button in _appearance.html.twig
     * for why a peek is not a preference.
     */
    togglePreview() {
        if (false === this.hasPreviewRegionTarget) {
            return;
        }

        const region = this.previewRegionTarget;
        const show = region.classList.contains('hidden');

        region.classList.toggle('hidden', !show);
        region.classList.toggle('flex', show);

        // Every control pointing at this region, whichever one was pressed.
        this.element
            .querySelectorAll('[data-appearance-preview-toggle]')
            .forEach((button) => button.setAttribute('aria-expanded', show ? 'true' : 'false'));

        // The label is the accessible name and it says what the control does
        // NEXT, which aria-expanded alone does not tell anyone reading the
        // button. Both strings come off the markup so they are translated once,
        // in the catalogue, rather than a second time in here.
        if (this.hasPreviewToggleLabelTarget && this.hasPreviewToggleTarget) {
            const { showLabel, hideLabel } = this.previewToggleTarget.dataset;

            this.previewToggleLabelTarget.textContent = (show ? hideLabel : showLabel) ?? '';
        }

        if (this.hasPreviewToggleIconTarget) {
            this.previewToggleIconTarget.classList.toggle('fa-eye', !show);
            this.previewToggleIconTarget.classList.toggle('fa-eye-slash', show);
        }

        // Revealed, the card is the FIRST thing in the stack — above the header
        // that revealed it. Left where they were, the reader is looking at the
        // controls with the preview off the top of the screen until they think
        // to scroll up, which is a disclosure that discloses nothing. Bringing
        // it to the top of the scroll port is also exactly where it will stick,
        // so nothing moves again afterwards.
        if (show) {
            region.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
    }

    /* ── Generic: a control that moves several variables at once ───────────
     *
     * The original `slide()` maps one input to one variable through
     * data-css-variable, which is right for a slider and useless for the
     * controls added since: preview lines is four properties, unread emphasis
     * is two, a font family is a whole stack. Rather than a handler per
     * control, each option carries the exact `{variable: value}` object it
     * means in `data-css-vars`, and this applies it.
     *
     * The values are CSS, not settings — `-webkit-box`, `nowrap`, `3px` —
     * because deriving CSS from a setting is what AppearanceRenderer does on
     * the server, and doing it a second time here in a different language is
     * how a live preview comes to disagree with a reload. Twig writes both
     * from the same enum.
     */
    applyVars(event) {
        const source = event.currentTarget.selectedOptions
            ? event.currentTarget.selectedOptions[0]
            : event.currentTarget;

        this.setVars(source.dataset.cssVars);
        this.queue();
    }

    setVars(json) {
        if (!json) {
            return;
        }

        Object.entries(JSON.parse(json)).forEach(([variable, value]) => {
            this.root.style.setProperty(variable, String(value));
        });
    }

    /**
     * An on/off list setting.
     *
     * The checkbox is not the field. save() collects `input.value`, which for
     * a checkbox is "on" whether or not it is ticked, so the value that gets
     * posted lives in a hidden input beside it and this keeps the two in step
     * — the same shape as the ink and main-pane "theme default" boxes.
     */
    toggleListOption(event) {
        const box = event.currentTarget;
        const field = this.element.querySelector(`[data-toggle-field="${box.dataset.toggles}"]`);

        if (field) {
            field.value = box.checked ? '1' : '0';
        }

        this.setVars(box.checked ? box.dataset.cssVarsOn : box.dataset.cssVarsOff);
        this.queue();
    }

    /* ── Per-surface density ───────────────────────────────────────────────
     *
     * Three surfaces, each either following the global density or overriding
     * it, resolved into the variables below. Recomputed whole on every change
     * rather than patched: a surface set to "follow" has to move when the
     * GLOBAL density moves, and the only way a patch could do that is by
     * knowing which surfaces are following — which is the same loop, written
     * twice.
     *
     * Every number comes off the global density radios, which are Density's
     * own scales rendered by Twig. Nothing here knows what "cosy" measures.
     *
     * The map is `--surface-<name>-<suffix>` to the dataset key holding it.
     * Each surface consumes a different scale — the list's row and the reading
     * pane's message block were 10px and 16px before density reached them, and
     * both keep those numbers at Comfortable so no existing install moves (see
     * Density::listRowPadding()) — and the sidebar consumes five, because it is
     * the one surface built out of rows at two tiers whose gap between rows is
     * not the shell gutter.
     */
    static SURFACE_VARS = {
        sidebar: {
            'row-y': 'rowY',
            'tree-y': 'treeY',
            'row-gap': 'rowGap',
            'section-y': 'sectionY',
            gap: 'gap',
        },
        list: { 'row-y': 'listRowY', gap: 'gap' },
        reading: { 'row-y': 'readingRowY', gap: 'gap' },
    };

    pickSurfaceDensity() {
        this.refreshSurfaces();
        this.queue();
    }

    refreshSurfaces() {
        const global = this.element.querySelector('[data-settings--appearance-field="density"]:checked');

        if (!global) {
            return;
        }

        Object.entries(this.constructor.SURFACE_VARS).forEach(([surface, variables]) => {
            const chosen = this.element.querySelector(
                `[data-surface-density="${surface}"]:checked`,
            );

            const source = chosen && chosen.value !== ''
                ? this.element.querySelector(
                    `[data-settings--appearance-field="density"][value="${chosen.value}"]`,
                )
                : global;

            if (!source) {
                return;
            }

            Object.entries(variables).forEach(([suffix, key]) => {
                this.root.style.setProperty(`--surface-${surface}-${suffix}`, source.dataset[key]);
            });
        });
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

        // Pane opacity is not the user's number alone once a background is in
        // play — see applyAlphaFloor(). Written raw first and corrected here,
        // rather than special-cased above, so this stays the one place that
        // knows about the floor.
        if (variable === '--pane-alpha') {
            this.applyAlphaFloor();
        }

        this.queue();
    }

    /**
     * The tiles name their theme in `data-theme-name`, NOT `data-theme`.
     *
     * `data-theme` is the palette switch: every block in app.css that declares
     * one is selected by that attribute, so a tile carrying it re-declared the
     * whole palette on itself and its label was painted in the colours of the
     * theme it names rather than the theme you are looking at. That is what put
     * "Nord" on screen at 1.22:1 in Solar. The blocks are :root-scoped now as
     * well, so neither half of the fault can come back on its own.
     */
    pickTheme(event) {
        const theme = event.currentTarget.dataset.themeName;

        this.themeTarget.value = theme;

        this.root.dataset.theme = theme;
        this.root.classList.toggle('dark', this.resolvesDark(event.currentTarget));

        this.element.querySelectorAll('[data-theme-name]').forEach((button) => {
            button.classList.toggle('ring-2', button.dataset.themeName === theme);
            button.classList.toggle('ring-accent', button.dataset.themeName === theme);
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
        if (button.dataset.themeName === 'system') {
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
                    // A layout that seeds a density has to carry the surfaces
                    // following it, exactly as a manual density change does.
                    this.refreshSurfaces();
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
        // Any surface still set to "follow" moves with it — see
        // refreshSurfaces(), which is why this is a recompute and not a patch.
        this.refreshSurfaces();
        this.queue();
    }

    /* ── Background ────────────────────────────────────────────────────────
     *
     * Every kind applies on the spot. Until this existed exactly one of them
     * did — the upload, which set --app-bg from the URL it got back — so
     * choosing a preset, choosing a colour, or going back to Theme changed
     * nothing on screen until the next page load. The setting saved correctly
     * the whole time, which is why it read as "backgrounds need a reload"
     * rather than as a broken control.
     *
     * The rule this obeys is the one in the class docblock above: a live switch
     * has to land EXACTLY where a reload would. So nothing here composes a CSS
     * value. Each tile carries `data-bg-value`, which Twig filled in from
     * BackgroundResolver — the same method AppearanceRenderer calls — and this
     * writes whatever it finds, or removes the property when it is empty, which
     * is how Theme is spelled server-side.
     *
     * The colour is the one that cannot be a fixed string, since it changes as
     * the picker moves. It gets the FORMAT from the server instead
     * (`data-bg-format`, rendered as `linear-gradient(%s, %s)` by
     * BackgroundResolver::solid) and substitutes the hex into it, so the shape
     * of the value still has one definition and it is not this file's.
     */
    pickBackground(event) {
        if (event.currentTarget.getAttribute('data-settings--appearance-field') === 'backgroundPreset') {
            this.checkKind('preset');
        }

        this.applyBackground();
        this.queue(0);
    }

    /**
     * The colour picker: choosing a colour is choosing the Solid kind.
     *
     * Same move pickBackground() makes for a preset thumbnail, and for the same
     * reason — the kind radio is sr-only and has no tile of its own, so nothing
     * else would ever tick it.
     */
    pickBackgroundSolid() {
        this.checkKind('solid');
        this.applyBackground();
        this.queue();
    }

    checkKind(kind) {
        const input = this.element.querySelector(
            `[data-settings--appearance-field="backgroundKind"][value="${kind}"]`,
        );

        if (input) {
            input.checked = true;
        }
    }

    /** The kind currently chosen — or `custom`, which has no tile to be checked. */
    backgroundKind() {
        return this.element.querySelector(
            '[data-settings--appearance-field="backgroundKind"]:checked',
        )?.value ?? 'custom';
    }

    /**
     * Put the chosen background on screen, and everything that travels with it.
     *
     * `url` is passed only by the upload, which learns its URL from the
     * response rather than from the DOM.
     */
    applyBackground(url = null) {
        const kind = this.backgroundKind();

        if (url !== null) {
            this.root.style.setProperty('--app-bg', `url("${url}")`);
        } else if (kind === 'solid') {
            this.root.style.setProperty('--app-bg', this.solidValue());
        } else {
            const source = kind === 'preset'
                ? this.element.querySelector('[data-settings--appearance-field="backgroundPreset"]:checked')
                : this.element.querySelector(`[data-settings--appearance-field="backgroundKind"][value="${kind}"]`);

            const value = source?.dataset.bgValue ?? '';

            // Empty is not "no opinion", it is Theme: AppearanceRenderer omits
            // --app-bg altogether there so the stylesheet's block for whichever
            // theme is on <html> answers instead.
            if (value === '') {
                this.root.style.removeProperty('--app-bg');
            } else {
                this.root.style.setProperty('--app-bg', value);
            }
        }

        if (this.hasBackgroundSolidSwatchTarget) {
            // The tile shows its colour whatever the kind — it is the swatch
            // for a choice that is still there when you look away — and shows
            // the selection ring only while it IS the choice. The ring is
            // toggled here rather than by `peer-checked:`, because the kind
            // radio it would key off is not a sibling of the swatch: the
            // <label> belongs to the colour input.
            this.backgroundSolidSwatchTarget.style.backgroundColor = this.backgroundSolidTarget.value;
            this.backgroundSolidSwatchTarget.classList.toggle('ring-2', kind === 'solid');
            this.backgroundSolidSwatchTarget.classList.toggle('ring-accent', kind === 'solid');
        }

        this.applyAlphaFloor();
    }

    solidValue() {
        const input = this.backgroundSolidTarget;

        return (input.dataset.bgFormat || '').replaceAll('%s', input.value);
    }

    /**
     * The opacity floor that comes WITH a background, and goes with it.
     *
     * AppearanceRenderer raises --pane-alpha (and --main-alpha, where the user
     * has set one) to 0.45 for any kind but Theme, because panel text over a
     * photograph is unreadable below that. It is part of what a background
     * change means, so a live change that skipped it disagreed with the reload
     * in the other direction: panes stayed see-through until you refreshed, and
     * going back to Theme left them stuck at the floor.
     */
    applyAlphaFloor() {
        const floor = this.backgroundKind() !== 'theme';
        const clamp = (value) => (floor ? Math.max(0.45, value) : value);

        // By field rather than by target: the glass sliders are rendered from
        // one loop and carry the field name only.
        const paneAlpha = this.element.querySelector('[data-settings--appearance-field="paneAlpha"]');

        if (paneAlpha) {
            this.root.style.setProperty('--pane-alpha', String(clamp(Number(paneAlpha.value))));
        }

        if (false === this.hasMainAlphaFieldTarget) {
            return;
        }

        // Empty means "match the panes", which is an absent variable rather
        // than a computed one — the same thing toggleMainAlphaMatch does.
        if (this.mainAlphaFieldTarget.value === '') {
            this.root.style.removeProperty('--main-alpha');

            return;
        }

        this.root.style.setProperty('--main-alpha', String(clamp(Number(this.mainAlphaFieldTarget.value))));
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
            // The server has just set the kind to Custom, which is the one kind
            // with no tile — so the tile that WAS ticked has to be unticked, or
            // the next save would post it and quietly undo the upload. The
            // template says as much about why `custom` has no radio; this is
            // what keeps the DOM honest about it at runtime.
            this.element
                .querySelectorAll('[data-settings--appearance-field="backgroundKind"]')
                .forEach((radio) => { radio.checked = false; });

            this.applyBackground(result.url);
            this.remember();
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
        this.applyAlphaFloor();
        this.queue();
    }

    toggleMainAlphaMatch(event) {
        if (event.currentTarget.checked === true) {
            this.mainAlphaFieldTarget.value = '';
            this.mainAlphaTarget.classList.add('opacity-40', 'pointer-events-none');
        } else {
            this.mainAlphaFieldTarget.value = this.mainAlphaTarget.value;
            this.mainAlphaTarget.classList.remove('opacity-40', 'pointer-events-none');
        }
        this.applyAlphaFloor();
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
