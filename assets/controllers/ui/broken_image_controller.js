import { Controller } from "@hotwired/stimulus";

/**
 * Take an image off the page when it fails to load.
 *
 * For the attachment thumbnails, which are generated lazily and may legitimately
 * not exist: a PDF, a format GD cannot read, a file whose thumbnail has been
 * pruned. The chip is perfectly good without one — it falls back to a paperclip
 * — but a broken-image icon in the middle of it is not a fallback, it is a
 * defect.
 *
 * This was `onerror="this.remove()"`, which the enforced CSP refuses: a nonce
 * cannot authorise an inline event handler. So the icon stayed. It is the kind
 * of thing nobody reports, because a broken thumbnail looks like a broken
 * attachment rather than like a bug in the page.
 *
 * `error` does not bubble, and does not need to — Stimulus binds the listener
 * to the element carrying the action, which is the image itself.
 */
export default class extends Controller {
    remove() {
        this.element.remove();
    }
}
