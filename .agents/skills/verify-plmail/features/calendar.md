# Calendar

The calendar shows the user's events by day, three days, week or month, beside the mail or on its own page, and creates events from a dialog.

## Sub-features

- `calendar-views` switches between Day, 7 days and Month.
- `calendar-create` creates an event from `New event` and shows it in the grid.
- `calendar-edit` opens an event and edits it.
- `calendar-pane` docks the calendar beside the mail; `tests/e2e/calendar-pane.spec.ts` drives it.

## How to get to it (user POV)

- The calendar button in the top bar, or `/calendar/week`.
- The view links `Day`, `7 days` and `Month` above the grid; `7 days` lives at `/calendar/week`.
- `New event` above the grid.

## Driving it with Playwright

Preconditions:

- The demo mailbox is seeded with a week of events from today.

- **Views.** `page.goto("/calendar/week")`, then `getByRole("link", { name: "Month", exact: true }).click()`; the grid changes and the URL follows to `/calendar/month`. `7 days` returns to `/calendar/week`.
- **Create.** `getByRole("button", { name: "New event", exact: true }).click()`. In `#modal-backdrop`, fill `getByLabel("Title")` and press `getByRole("button", { name: "Save" })`. The event shows as `getByRole("button", { name: /<title>/ })` in the grid.
- **Edit.** Click the event, then the dialog's `getByRole("link", { name: "Edit" })`; `getByLabel("Title")` holds the saved title.
- **Proof.** `shot` the dialog filled in and the grid after Save, then reload and see the event again.

## Gotchas

- Times render in `Europe/Berlin`. Work out an hour you assert in that zone, never with Node's local clock (`tests/e2e/support/config.ts`, `APP_TIMEZONE`).
- The week grid scrolls; an event outside the visible hours needs a scroll before a shot shows it.
- Wait for `[data-controller~="ui--toast"]` to clear before a shot of the grid.
