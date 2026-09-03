import { Controller } from "@hotwired/stimulus";

/**
 * Fills the three fields that have to agree with each other.
 *
 * WHY THESE THREE MOVE TOGETHER
 * An embedding model, the instruction its queries need, and the similarity
 * threshold its scores live on are not three settings. They are one setting
 * written in three boxes, and any two of them from different models produce a
 * search that is confidently wrong: the right model with the wrong threshold
 * finds everything or nothing, and the right threshold with a missing
 * instruction ranks newsletters above the mail somebody was looking for. Both
 * failures look like the feature being poor rather than like a setting being
 * mixed, which is why choosing is one click and not three.
 *
 * NOTHING IS SAVED HERE. Same gesture as the prompt fields: this fills the
 * boxes and the administrator still has to press Save, so a preset can be
 * looked at, compared with the one below it, and abandoned.
 *
 * The input event is dispatched by hand because assigning to `.value` does not
 * fire one, and Turbo's form restoration listens for it.
 */
export default class extends Controller {
    static targets = ["model", "instruction", "similarity"];

    apply(event) {
        event.preventDefault();

        const { model, instruction, similarity } = event.currentTarget.dataset;

        this.#set(this.modelTarget, model);
        this.#set(this.instructionTarget, instruction ?? "");
        this.#set(this.similarityTarget, similarity);

        // Focus lands on the model field rather than staying on a button whose
        // job is done, so a screen reader user hears what changed rather than
        // being left where they were with no announcement at all.
        this.modelTarget.focus();
    }

    #set(field, value) {
        if (undefined === field || null === field) {
            return;
        }

        field.value = value;
        field.dispatchEvent(new Event("input", { bubbles: true }));
    }
}
