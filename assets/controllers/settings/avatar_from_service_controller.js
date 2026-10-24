import { Controller } from "@hotwired/stimulus";

/**
 * Posts the profile form with a picture chosen in the file picker.
 *
 * The picker renders in the body-level modal frame and the form is in the page,
 * so they cannot address each other through the DOM; the picker announces its
 * choice on `document` (integration_picker_controller.js) and this is what
 * listens. It adds the two fields ProfileType expects for a picture from a
 * service — they are not in the form until there is a choice, so an ordinary
 * save posts nothing about it — and submits.
 *
 * Submitting, rather than filling the fields and waiting for Save: choosing and
 * saving have always been one action here, and a picture picked in a dialog
 * that then needs a second click somewhere behind it reads as a pick that did
 * nothing.
 *
 * The server does not trust either field. ProfileUpdater matches the
 * integration id against the user's own connections and AvatarFromIntegration
 * decides what the bytes are.
 */
export default class extends Controller {
    static values = {
        /** The form's name, which prefixes its fields: `profile[avatarFileId]`. */
        name: String,
    };

    picked(event) {
        const { integrationId, fileId } = event.detail ?? {};

        if (undefined === integrationId || undefined === fileId) {
            return;
        }

        this._set("avatarIntegrationId", integrationId);
        this._set("avatarFileId", fileId);

        this.element.requestSubmit();
    }

    /** A hidden field, replaced rather than duplicated on a second pick. */
    _set(field, value) {
        const name = `${this.nameValue}[${field}]`;

        this.element
            .querySelectorAll("input[type=hidden]")
            .forEach((input) => {
                if (input.name === name) {
                    input.remove();
                }
            });

        const input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = value;
        this.element.append(input);
    }
}
