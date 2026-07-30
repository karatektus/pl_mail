import { Controller } from "@hotwired/stimulus"

/**
 * Makes a file input look like the rest of the form.
 *
 * The native control renders whatever the browser feels like — in German
 * Chrome, "Datei auswählen / Keine ausgewählt" — and cannot be styled. So the
 * input is visually hidden inside a label that carries the design, and this
 * fills that label in once something is chosen: the filename, and a thumbnail
 * when the file is an image, which for an avatar is the only confirmation that
 * matters.
 */
export default class extends Controller {
    static targets = ["input", "name", "preview", "icon"]

    pick() {
        const file = this.inputTarget.files?.[0]

        if (!file) {
            this._clearPreview()
            return
        }

        this.nameTarget.textContent = file.name

        if (!file.type.startsWith("image/")) {
            this._clearPreview()
            return
        }

        // Revoked on the next pick rather than on load: Safari has raced the
        // decode and shown a broken image when revoking immediately.
        this._revoke()
        this._url = URL.createObjectURL(file)

        this.previewTarget.src = this._url
        this.previewTarget.hidden = false
        this.iconTarget.hidden = true
    }

    disconnect() {
        this._revoke()
    }

    _clearPreview() {
        this._revoke()
        this.previewTarget.hidden = true
        this.iconTarget.hidden = false
    }

    _revoke() {
        if (this._url) {
            URL.revokeObjectURL(this._url)
            this._url = null
        }
    }
}
