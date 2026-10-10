import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() { this.update(); }
    update() {
        const form = this.element;
        const value = name => form.querySelector(`[name="ai_settings[${name}]"]`);
        const openai = value('chatProvider')?.value === 'openai';
        const shared = value('embeddingSharedConnection')?.checked;
        const embeddings = value('searchEnabled')?.checked;
        const compatibleEmbedding = shared ? openai : value('embeddingProvider')?.value === 'openai';
        const model = value('embeddingModel');
        if (model) {
            if (compatibleEmbedding) model.removeAttribute('list');
            else model.setAttribute('list', 'embedding-model-presets');
        }
        for (const panel of form.querySelectorAll('[data-ai-panel]')) {
            const show = { generationOpenai: openai, generationOllama: !openai, embeddings,
                independent: !shared, embeddingOllama: !compatibleEmbedding }[panel.dataset.aiPanel];
            panel.style.display = show ? '' : 'none';
        }
    }
}
