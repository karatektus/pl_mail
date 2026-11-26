# Compose

Compose opens a writing window docked over the mail (or inline under a conversation for a reply), suggests recipients from contacts, and sends with a short window to undo.

## Sub-features

- `compose-open` opens a new message with the caret in To.
- `compose-recipients` suggests contacts as you type and adds the chosen one.
- `compose-write` takes a subject and a body with formatting.
- `compose-send` closes the window at once and offers Undo in a toast.
- `compose-reply` opens an inline reply under a conversation.
- `compose-template` inserts one of the seeded templates.

## How to get to it (user POV)

- Choose `Compose` at the top of the sidebar, or press `c`.
- In an open conversation, choose `Reply`.
- From the command box, type `compose`.

## Driving it with Playwright

Preconditions:

- The demo mailbox is seeded, so contacts such as Priya Raman exist.

- **Open.** `page.getByRole("link", { name: "Compose" }).first().click()`. `#compose_dock .compose-window` is visible.
- **Recipient.** Fill `#compose_dock .ts-control input` with `priya`, then click the first `.ts-dropdown .option`. Priya Raman shows as a chip.
- **Write.** Fill `input[name="compose[subject]"]` and the editor `[data-compose--compose-toolbar-target="editor"]`.
- **Send.** Click the window's `getByRole("button", { name: /^Send/ })`. The window is gone within five seconds and `#toast-region` says `Sending` with an `Undo` button.
- **Reply.** Open a conversation, `getByRole("link", { name: "Reply", exact: true }).first()`, and the inline editor is `#compose_inline [data-compose--compose-toolbar-target="editor"]`.
- **Proof.** `shot` the filled window before Send and the toast with its Undo after it.

## Gotchas

- The test stack's message bus is in memory, so the send job never runs on its own and no SMTP is reached: a send from the demo user stays pending, and Sent proves nothing about delivery. `tests/e2e/compose-signature-sent.spec.ts` shows how a spec runs the job by hand with `app:test:finish-send` for its own user.
- The window animates open; `settle` before a shot.
- Drafts autosave. A spec that leaves a draft behind changes the Drafts count for later tests in the same run.
