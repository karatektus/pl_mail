import { Controller } from "@hotwired/stimulus";

const STORAGE_KEY = "tasks.sections";

function remembered() {
    try {
        return JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? "{}");
    } catch {
        return {};
    }
}

/*
 * The groups somebody folded or unfolded by hand, by name: each one stays as they
 * left it rather than going back to how the page draws it. Out here for the same
 * reason as tasks--row's set, since a tick reloads the pane and every group with it.
 */
const chosen = new Map(Object.entries(remembered()));

/**
 * One group of the pane, which keeps the way it was last folded.
 *
 * Values:
 *   name  String  the group, as TaskPane keys it
 */
export default class extends Controller {
    static values = { name: String };

    connect() {
        if (chosen.has(this.nameValue)) {
            this.element.open = chosen.get(this.nameValue);
        }
    }

    toggled() {
        chosen.set(this.nameValue, this.element.open);

        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(Object.fromEntries(chosen)));
        } catch {
            // See chosen: this page keeps working from the map.
        }
    }
}
