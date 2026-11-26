# Mail

The inbox lists conversations newest first, split into Primary, Promotions and Updates tabs. Opening a row shows the conversation in the reading pane; each row offers archive, delete, star and snooze, on hover with a mouse and by swiping on a phone.

## Sub-features

- `mail-list` shows the inbox, its tabs and their counts.
- `mail-open` opens a conversation and shows its messages.
- `mail-archive` removes a conversation from the inbox and puts it in Archive.
- `mail-star` stars a conversation so it shows in Starred.
- `mail-snooze` hides a conversation until a time, listed under Snoozed.
- `mail-swipe` archives or marks read by swiping a row on a phone.

## How to get to it (user POV)

- Open `/mail/inbox`, or choose Inbox in the sidebar.
- Choose a row to open it; `j`/`k` and Enter do the same from the keyboard.
- Hover a row for its Archive, Delete, Star and Snooze buttons.
- On a phone, drag a row left to reveal Archive, right for read or unread.
- The sidebar's More reaches Archive (`/mail/archive`), Snoozed (`/mail/snoozed`), Sent and Trash.

## Driving it with Playwright

Preconditions:

- The demo mailbox is seeded; the Primary tab holds `Bookshelf dimensions` from Priya Raman.

- **List.** `page.goto("/mail/inbox")`, then `mailRow(page, "Bookshelf dimensions")` is visible and the tab bar shows `Primary` with a count.
- **Open.** `row.click()`. The reading pane shows `alcove is 182cm`.
- **Archive.** `rowAction(mailRow(page, SUBJECT), "Archive")` from `tests/e2e/support/rows.ts`. The row leaves the list, stays gone after `page.reload()`, and `mailRow` finds it on `/mail/archive`.
- **Star.** `rowAction(row, "Star this message")`; the row's button becomes `Unstar this message` and the row shows on `/mail/starred`.
- **Snooze.** `rowAction(row, "Snooze")` opens a menu of times; after choosing one the row leaves the inbox and shows its wake time on `/mail/snoozed`. `tests/e2e/snoozed-list.spec.ts` drives this.
- **Swipe.** `tests/e2e/row-swipe.spec.ts` drives a real touch drag on `[data-swipe-content]` and the revealed `[data-swipe-commit]` button; reuse its helpers in a phone spec.
- **Proof.** `shot` the inbox, the open conversation, the inbox after the action and the folder it moved to. `scratch/archive.spec.ts` in the SKILL.md example is this recipe.

## Gotchas

- Row buttons are `visibility: hidden` until hovered, so `getByRole` finds nothing on an unhovered row. Use `rowAction`, which re-hovers until the button is there; never `force: true`.
- Row actions do not exist on the phone; skip the phone project for a hover recipe and drive the swipe instead.
- An archived conversation stays archived for the rest of the run, so the second project in the same run no longer finds it in the inbox.
- `Re: Lieferschein 4471` is a seeded bounce from `Mail Delivery System`; it is meant to look like a failure.
