import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        updateUrl: String,
        uploadUrl: String,
        importUrl: String,
        resetUrl: String,
    };

    static targets = ['paneAlpha', 'paneBlur', 'radius', 'scrimAlpha', 'accent', 'theme', 'importInput', 'uploadInput'];
    connect() {
        this.root = document.documentElement;
        this.pending = null;
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
        const isDark = event.currentTarget.dataset.dark === '1';

        this.themeTarget.value = theme;

        this.root.dataset.theme = theme;
        this.root.classList.toggle('dark', isDark);

        this.element.querySelectorAll('[data-theme]').forEach((button) => {
            button.classList.toggle('ring-2', button.dataset.theme === theme);
            button.classList.toggle('ring-accent', button.dataset.theme === theme);
        });

        this.queue();
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
        if (event.currentTarget.dataset.appearanceField === 'backgroundPreset') {
            const kindInput = this.element.querySelector('[data-appearance-field="backgroundKind"][value="preset"]');

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

        this.element.querySelectorAll('[data-appearance-field]').forEach((input) => {
            if (input.type === 'radio' && input.checked === false) {
                return;
            }

            payload[input.dataset.appearanceField] = input.value;
        });

        try {
            await fetch(this.updateUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
        } catch (error) {
            console.error('Appearance save failed', error);
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

    contrast(hex) {
        const [r, g, b] = this.channels(hex).split(' ').map(Number);
        const luminance = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;

        return luminance > 0.6 ? '24 24 27' : '255 255 255';
    }
}
