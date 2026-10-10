import { Controller } from '@hotwired/stimulus';
export default class extends Controller {
    static targets = ['rows'];
    connect() {
        const names = [...this.rowsTarget.querySelectorAll('input[name]')].map(el => Number(el.name.match(/\[(\d+)\]/)?.[1] ?? -1));
        this.rowsTarget.dataset.index = Math.max(-1, ...names) + 1;
        if (!this.rowsTarget.querySelector('[data-header-row]')) this.add();
        this.rowsTarget.querySelectorAll('[data-header-row]').forEach(row => this.updateMode(row));
    }
    add() {
        const index = Number(this.rowsTarget.dataset.index); this.rowsTarget.dataset.index = index + 1;
        this.rowsTarget.insertAdjacentHTML('beforeend', this.rowsTarget.dataset.prototype.replaceAll('__name__', String(index)));
        this.updateMode(this.rowsTarget.lastElementChild);
    }
    remove(event) {
        event.currentTarget.closest('[data-header-row]').remove();
        if (!this.rowsTarget.querySelector('[data-header-row]')) this.add();
    }
    modeChanged(event) { this.updateMode(event.currentTarget.closest('[data-header-row]')); }
    updateMode(row) {
        const automatic = row.querySelector('[data-header-mode]').value === 'session';
        row.querySelector('[data-header-fixed]').hidden = automatic;
        row.querySelector('[data-header-fixed] input').disabled = automatic;
        row.querySelector('[data-header-automatic]').hidden = !automatic;
    }
}
