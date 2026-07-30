import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this.onDocumentClick = this.onDocumentClick.bind(this);
        document.addEventListener('click', this.onDocumentClick);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
    }

    toggle(event) {
        event.stopPropagation();
        this.menuTarget.classList.toggle('hidden');
    }

    onDocumentClick(event) {
        if (false === this.element.contains(event.target)) {
            this.menuTarget.classList.add('hidden');
        }
    }
}
