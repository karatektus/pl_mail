import { Controller } from "@hotwired/stimulus";

/**
 * A booking page's day hours: minutes-from-midnight in the two hidden fields
 * the form posts, HH:MM in the two the calendar--when picker edits.
 *
 * This used to be an inline <script> at the foot of the form, and the enforced
 * Content-Security-Policy refused it — silently, so the picker opened empty on
 * every edit and posted whatever the hidden fields started with. It is a
 * controller now because the form arrives in the modal frame through Turbo,
 * and a nonce cannot follow a fragment there (see
 * App\Security\Csp\ContentSecurityPolicyListener).
 *
 * It sits on the <form>, an ancestor of the picker, so it connects first and
 * the picker already finds the stored hours when it reads its inputs. Setting
 * `.value` later would be fine too — the picker redraws on assignment.
 *
 * Targets:
 *   startMinute / endMinute — the posted hidden fields, in minutes
 *   start / end             — the picker's hidden HH:MM inputs
 */
export default class extends Controller {
    static targets = ["startMinute", "endMinute", "start", "end"];

    connect() {
        this.startTarget.value = toClock(this.startMinuteTarget.value);
        this.endTarget.value = toClock(this.endMinuteTarget.value);
    }

    /** `input` bubbling up from anywhere in the form; only the two clocks count. */
    sync(event) {
        if (event.target === this.startTarget) {
            this.startMinuteTarget.value = String(toMinutes(this.startTarget.value));
        } else if (event.target === this.endTarget) {
            this.endMinuteTarget.value = String(toMinutes(this.endTarget.value));
        }
    }
}

function toClock(minutes) {
    const value = Math.max(0, Math.min(1440, parseInt(minutes, 10) || 0));

    return String(Math.floor(value / 60)).padStart(2, "0") + ":" + String(value % 60).padStart(2, "0");
}

function toMinutes(clock) {
    const [hours, minutes] = String(clock || "").split(":");

    return (parseInt(hours, 10) || 0) * 60 + (parseInt(minutes, 10) || 0);
}
