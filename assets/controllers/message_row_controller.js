import { Controller } from "@hotwired/stimulus";

// Handles per-row interactions in the message list: checkbox, star toggle,
// and hover quick-actions (archive, delete, snooze, mark read/unread).
//
// All action methods currently only stop the click from bubbling up to the
// row's navigation link. None of them call the backend yet — that wiring
// lands once the corresponding routes exist (star is closest, since
// Message.starredAt already exists in the schema).
export default class extends Controller {
    static values = { id: Number };

    // Prevent the checkbox/star/hover-action click from also triggering
    // the row's <a data-action="click->mail-pane#open">.
    stop(event) {
        event.stopPropagation();
    }

    toggleSelect(event) {
        event.stopPropagation();
        // TODO: track selected message ids for bulk actions (archive, delete, etc.)
    }

    toggleStar(event) {
        event.stopPropagation();
        // TODO: POST/PATCH to a "star" endpoint, then toggle the icon's
        // filled state and color locally for instant feedback.
    }

    archive(event) {
        event.stopPropagation();
        // TODO: wire up once an "archive" concept/mailbox exists.
    }

    delete(event) {
        event.stopPropagation();
        // TODO: wire up once trash semantics are implemented.
    }

    snooze(event) {
        event.stopPropagation();
        // TODO: wire up once snooze is implemented.
    }

    markRead(event) {
        event.stopPropagation();
        // TODO: wire up once a "mark as read/unread" endpoint exists.
    }
}
