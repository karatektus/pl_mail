import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        updateUrl: String,
        uploadUrl: String,
        importUrl: String,
        resetUrl: String,
    };

    static targets = ['paneAlpha', 'paneBlur', 'radius', 'scrimAlpha', 'accent', 'theme', 'logoStyle', 'logoLinked', 'logoGrid', 'importInput', 'uploadInput',
        'inkColor', 'inkColorField', 'inkDefault', 'inkCustom', 'inkDerived', 'inkMuted', 'inkMutedField', 'inkFaint', 'inkFaintField',
        'mainTint', 'mainTintField', 'mainTintDefault', 'mainTintCustom', 'mainAlpha', 'mainAlphaField', 'mainAlphaMatch', 'mainAlphaCustom',
        'backgroundSolid', 'backgroundSolidSwatch',
        'previewRegion', 'previewToggle', 'previewToggleLabel', 'previewToggleIcon',
        'moreThemes', 'moreThemesButton'];

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
     * An on/off list setting, as a two-segment radio group.
     *
     * The radios are not the field: they carry no field attribute, so save()
     * never reads them. Each change writes the hidden input named by
     * `data-toggle-field`, which is what gets posted as '1'/'0' — the same
     * contract the checkbox kept, with the radio's own value ('1' = shown)
     * standing in for `checked`.
     */
    toggleListOption(event) {
        const input = event.currentTarget;
        const on = input.value === '1';
        const field = this.element.querySelector(`[data-toggle-field="${input.dataset.toggles}"]`);

        if (field) {
            field.value = on ? '1' : '0';
        }

        this.setVars(on ? input.dataset.cssVarsOn : input.dataset.cssVarsOff);
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

    /* ── The "More" tile in the theme picker ────────────────────────────────
     *
     * The seven classics are always on the page; the logo themes wait in a
     * hidden grid (hidden by the CONTAINER — the tiles themselves stay in the
     * DOM, which pickTheme's ring sweep and the linked-logo repaint rely on).
     * This reveals them for the rest of the page visit and retires the button:
     * a one-way disclosure, not a toggle, because "fold them away again" is
     * what the next page load does by itself — the server renders only the
     * chosen logo theme among the visible tiles.
     */
    showMoreThemes() {
        if (this.hasMoreThemesTarget) {
            this.moreThemesTarget.classList.remove('hidden');
        }

        if (this.hasMoreThemesButtonTarget) {
            this.moreThemesButtonTarget.setAttribute('aria-expanded', 'true');
            this.moreThemesButtonTarget.classList.add('hidden');
        }
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

        // While the mark is linked, the theme dresses it too: repaint the
        // topbar and the tab icon from the logo tile that shares this theme's
        // name — the grid is hidden but still in the DOM, which is exactly why
        // it stays there. The classic seven have no namesake tile and fall
        // back to the product default, mirroring effectiveLogoStyle().
        if (this.hasLogoLinkedTarget && this.logoLinkedTarget.value === '1') {
            const tile = this.linkedLogoTile(theme);

            if (tile) {
                this.repaintLogo(tile);
            }
        }

        this.queue();
    }

    /**
     * A logo colourway tile was clicked.
     *
     * The repaint targets only the marks that FOLLOW the setting —
     * `[data-logo-live]`, today the topbar's — because the tiles beside this
     * one each preview their OWN style and must not be painted over by the
     * choice they offer. The strokes travel on the tile as two JSON lists
     * (light and dark chrome), indexed the way _logo_mark.html.twig draws,
     * so this stays geometry-blind: whichever chrome is on decides which
     * list is painted, stroke by stroke.
     */
    pickLogo(event) {
        const tile = event.currentTarget;
        const style = tile.dataset.logoName;

        this.logoStyleTarget.value = style;

        this.element.querySelectorAll('[data-logo-name]').forEach((button) => {
            button.classList.toggle('ring-2', button.dataset.logoName === style);
            button.classList.toggle('ring-accent', button.dataset.logoName === style);
        });

        this.repaintLogo(tile);

        this.queue();
    }

    /**
     * The linked/independent switch above the logo grid.
     *
     * Linked hides the grid (a choice the theme is making for you is not a
     * grid to pick from) and dresses the mark for the current theme;
     * independent reveals the grid and puts the mark back into the style it
     * holds — logoStyle is stored either way, so nothing is lost by flipping
     * back and forth.
     */
    toggleLogoLinked(event) {
        const linked = event.currentTarget.value === '1';

        this.logoLinkedTarget.value = linked ? '1' : '0';

        if (this.hasLogoGridTarget) {
            this.logoGridTarget.classList.toggle('hidden', linked);
        }

        const tile = linked
            ? this.linkedLogoTile(this.themeTarget.value)
            : this.element.querySelector(`[data-logo-name="${this.logoStyleTarget.value}"]`);

        if (tile) {
            this.repaintLogo(tile);
        }

        this.queue();
    }

    /**
     * The tile a linked mark paints from: the one sharing the theme's name,
     * or the product default for the classic seven themes that have none —
     * the same answer Appearance::effectiveLogoStyle() gives on the server.
     */
    linkedLogoTile(theme) {
        return this.element.querySelector(`[data-logo-name="${theme}"]`)
            ?? this.element.querySelector('[data-logo-name="berry"]');
    }

    /**
     * Paint one tile's colourway onto everything that follows the setting:
     * the topbar's live mark, stroke by stroke, and the tab icon.
     *
     * The favicon link is URL-versioned by style (see _favicon.html.twig)
     * because browsers keep a favicon cache that ignores ordinary
     * revalidation — a new style means a NEW href, which is also what makes
     * the tab repaint right now instead of after a hard reload.
     */
    repaintLogo(tile) {
        const strokes = JSON.parse(
            this.root.classList.contains('dark')
                ? tile.dataset.logoStrokesDark
                : tile.dataset.logoStrokes,
        );

        document.querySelectorAll('[data-logo-live] [data-logo-stroke]').forEach((stroke) => {
            stroke.setAttribute('stroke', strokes[Number(stroke.dataset.logoStroke)]);
        });

        document.querySelectorAll('link[rel="icon"][type="image/svg+xml"]').forEach((link) => {
            const url = new URL(link.href, window.location.origin);
            url.searchParams.set('v', tile.dataset.logoName);
            link.href = url.toString();
        });
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
        // The chosen radio itself. This was a <select>, reading
        // selectedOptions[0] and querying its own <option> children; the
        // control is now a segmented radio group like the rest of the page, so
        // the target IS the choice and the siblings are found through the form
        // rather than through it.
        const chosen = event.currentTarget;

        // Exactly one layout class may be on <html> at a time, so clear every
        // known one before adding this layout's (the default layout has none).
        this.element
            .querySelectorAll('[data-settings--appearance-field="layout"][data-layout-class]')
            .forEach((candidate) => {
                if (candidate.dataset.layoutClass !== '') {
                    this.root.classList.remove(candidate.dataset.layoutClass);
                }
            });

        if (chosen.dataset.layoutClass !== '') {
            this.root.classList.add(chosen.dataset.layoutClass);
        }

        this.applyDefaults(JSON.parse(chosen.dataset.layoutDefaults));
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

    /**
     * Switch how much the interface moves, on the spot.
     *
     * Writes the tier AND the tokens, which looks redundant and is not. The
     * attribute is what motion.css keys its own token block off and what
     * motion.js reads to decide whether to bother observing at all; the inline
     * custom properties are what AppearanceRenderer writes on a page load, and
     * they beat the stylesheet. Setting only the attribute would leave the old
     * tier's inline values winning and nothing would appear to change.
     */
    pickMotion(event) {
        const radio = event.currentTarget;

        this.root.dataset.motion = radio.dataset.motionValue;

        this.root.style.setProperty('--motion-fast', radio.dataset.motionFast);
        this.root.style.setProperty('--motion-base', radio.dataset.motionBase);
        this.root.style.setProperty('--motion-slow', radio.dataset.motionSlow);
        this.root.style.setProperty('--motion-lift', radio.dataset.motionLift);
        this.root.style.setProperty('--motion-scale', radio.dataset.motionScale);
        this.root.style.setProperty('--motion-stagger', radio.dataset.motionStagger);

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
        // Choosing a colour is choosing Custom: ticking the custom radio
        // unchecks Default through the shared name, the way unticking the old
        // checkbox did.
        this.inkCustomTarget.checked = true;
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

    /*
     * The Default/Custom segments. `change` fires on whichever radio was just
     * selected, so the radio's own value says which side was picked — Default
     * clears and disables exactly as checking the old box did, Custom seeds
     * the field from the picker exactly as unchecking did.
     */
    toggleInkDefault(event) {
        if (event.currentTarget.value === 'default') {
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
        this.mainTintCustomTarget.checked = true;
        this.root.style.setProperty('--rgb-main', this.channels(event.currentTarget.value));
        this.queue();
    }

    toggleMainTintDefault(event) {
        if (event.currentTarget.value === 'default') {
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
        this.mainAlphaCustomTarget.checked = true;
        this.applyAlphaFloor();
        this.queue();
    }

    toggleMainAlphaMatch(event) {
        if (event.currentTarget.value === 'follow') {
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
