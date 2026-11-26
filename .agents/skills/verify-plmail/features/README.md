# plMail verification map

The maintained source for proving what a plMail user sees. Read this index before driving, then use the matching feature file as the recipe. A change that touches a mapped feature drives every entry point its file lists.

## Baseline preconditions

- Every run starts from `verify` (see `../SKILL.md`), which brings up `compose.test.yaml` under its own Docker project on the QA host, empty, then migrates, seeds and checks it. Never drive staging or the live plMail: both hold Ben's real mail.
- The person signed in is `demo@plmail.test`, seeded with upstream's demo mailbox (`app:test:seed-demo`, `src/Service/Demo/`): ten inbox threads from fictional senders (Priya Raman, Hotel Aare, Kleinschmidt Werkzeug, Mum), the labels Family, Receipts and Travel, nine contacts, a week of calendar events and six templates. The account shows as `you@example.com`.
- Seed dates are relative to the day of the run, and the browser and server both run in `Europe/Berlin`.
- `desktop` is 1280×800 with a mouse. `phone` is 390×844, touch, at 2× scale.

## Driving conventions

- Specs import `test`, `expect`, `shot` and `settle` from `../support.ts`, whose `test` fails on a page error, a console error or a 5xx. Reuse the suite's helpers from `tests/e2e/support/` (`mailRow`, `rowAction`, `seed`), which already name the stable handles.
- Prefer roles and accessible names (`getByRole("link", { name: "Compose" })`). Fall back to the ids and `data-` attributes the suite uses, such as `#message-list`, `#compose_dock` and `#settings-palette`.
- Press, type and click as a person does. A `page.evaluate` or a POST that sets state proves nothing about the screen.
- Each test gets a fresh page signed in as the demo user. State a test writes, such as an archived thread, stays for the rest of that run, so a spec that mutates should run its desktop and phone halves on different rows or skip one.

## Proof and skip reporting

- Capture the action and its result: a `shot` before the press and after it.
- Confirm a write on a second view: reload, or open the folder the item moved to.
- A skipped test is not a pass. Report it with the reason Playwright printed in `output.log`.
- Report an entry point you could not reach, with the precondition it lacked; do not prove it through another one.

## Feature entry contract

Each file opens with an H1 and one paragraph on what the user sees, then four H2s in order: `Sub-features`, `How to get to it (user POV)`, `Driving it with Playwright` (starting with `Preconditions:`) and `Gotchas`.

## Features

- [Mail](./mail.md): the inbox list, its tabs, opening a conversation, and row actions (archive, star, snooze, swipe).
- [Compose](./compose.md): the compose window, recipients, and send with its undo.
- [Calendar](./calendar.md): the day, week and month views, and creating an event.
- [Command box](./command-box.md): ⌘K / Ctrl+K to run any shortcut or go anywhere.
- [Appearance](./appearance.md): themes and the look of the whole app.
