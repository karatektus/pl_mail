import { Controller } from "@hotwired/stimulus"

/**
 * contacts--form: the address book's two upload forms — a contact's photo,
 * which submits itself once a file is chosen, and the import page's file
 * picker. The contact form itself is contacts--editor.
 *
 * No inline handlers anywhere: the app's CSP refuses them.
 */
export default class extends Controller {
    static targets = ["fileInput", "uploadBtn", "status"]

    /** Auto-submit the photo upload form when a file is chosen. */
    submitForm(event) {
        if (event.target.files?.length) {
            event.target.form.requestSubmit()
        }
    }

    /**
     * Import page: a file was picked — show its name and submit. The form
     * also has a real submit button, so the upload works even if this
     * controller never loads; this is the convenience path, not the only one.
     */
    fileChosen(event) {
        const file = event.target.files?.[0]
        if (this.hasStatusTarget && file) {
            this.statusTarget.textContent = file.name
        }
        if (file) {
            event.target.form.requestSubmit()
        }
    }

    /**
     * Import page: "Upload & preview" with no file yet. The file input is
     * required but visually hidden, so the browser cannot show its validation
     * message and the click would silently do nothing — open the picker.
     */
    uploadClicked(event) {
        if (this.hasFileInputTarget && !this.fileInputTarget.files?.length) {
            event.preventDefault()
            this.fileInputTarget.click()
        }
    }

    /** Import page: the upload is on its way — say so instead of silence. */
    uploading() {
        if (this.hasUploadBtnTarget) {
            this.uploadBtnTarget.disabled = true
            this.uploadBtnTarget.classList.add("opacity-60", "pointer-events-none")
        }
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = this.statusTarget.dataset.uploading || "Uploading…"
        }
    }
}
