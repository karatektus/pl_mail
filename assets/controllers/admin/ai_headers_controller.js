import { Controller } from '@hotwired/stimulus';
export default class extends Controller {
    static targets = ['rows'];
    connect() {
        const names = [...this.rowsTarget.querySelectorAll('input[name]')].map(el => Number(el.name.match(/\[(\d+)\]/)?.[1] ?? -1));
        this.rowsTarget.dataset.index = Math.max(-1, ...names) + 1;
    }
    add() {
        const row = document.createElement('div');
        row.dataset.headerRow = ''; row.className = 'border border-line rounded-pane p-3 mb-3 space-y-2';
        const index = Number(this.rowsTarget.dataset.index); this.rowsTarget.dataset.index = index + 1;
        row.innerHTML = this.rowsTarget.dataset.prototype.replaceAll('__name__', String(index));
        const remove = this.element.querySelector('[data-action="admin--ai-headers#remove"]')?.cloneNode(true);
        if (remove) row.append(remove);
        else { const button = document.createElement('button'); button.type = 'button'; button.textContent = this.element.dataset.removeLabel; button.dataset.action = 'admin--ai-headers#remove'; row.append(button); }
        this.rowsTarget.append(row);
    }
    remove(event) { event.target.closest('[data-header-row]').remove(); }
}
