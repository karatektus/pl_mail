import { Controller } from "@hotwired/stimulus"

/**
 * Reloads the setup page in the language just chosen.
 *
 * Picking a language and then reading the rest of the form in English defeats
 * the point, so the choice takes effect at once rather than only after the
 * account exists. A full navigation, not a fetch: the locale is decided when the
 * request is routed, so the page has to be asked for again.
 *
 * What was already typed is carried across in the query string. Losing a
 * half-filled form because you corrected the language would be its own small
 * insult.
 */
export default class extends Controller {
    reload(event) {
        const form = this.element.closest("form")

        if (!form) {
            return
        }

        const url = new URL(window.location.href)
        url.searchParams.set("_locale", event.currentTarget.value)

        for (const [name, value] of new FormData(form).entries()) {
            // Passwords are not worth round-tripping through a URL, and the
            // token is minted fresh by the page being loaded.
            if (typeof value === "string" && value !== "" && !/password|_token|_locale/.test(name)) {
                url.searchParams.set(name, value)
            }
        }

        window.location.assign(url.toString())
    }
}
