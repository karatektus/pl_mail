# Command box

⌘K (Ctrl+K off a Mac) opens a box that runs any keyboard shortcut by name or jumps to any page or setting. Each result shows its own shortcut keys.

## Sub-features

- `cmdk-open` opens and closes the box.
- `cmdk-run` runs an action, such as Archive, on the highlighted conversation.
- `cmdk-go` goes to a page or a setting.
- `cmdk-context` offers actions only where they apply, such as Reply with a conversation open.

## How to get to it (user POV)

- Press ⌘K or Ctrl+K anywhere in the app.

## Driving it with Playwright

Preconditions:

- The inbox has rows; the body carries `data-controller~="ui--shortcuts"`.

- **Open.** `page.keyboard.press("ControlOrMeta+k")`. `#settings-palette` is visible.
- **Search.** Fill `#settings-palette` `getByRole("combobox")` with `archive`. The first `getByRole("option")` says Archive and its `kbd` reads `e`.
- **Run.** On the inbox with the box closed, press `j` to put the cursor on a row. Then open the box, search `archive` and press Enter. The box closes and the inbox has one row fewer.
- **Close.** Escape clears the field first, then closes the box.
- **Proof.** `shot` the open box with its results and the list after the action. `tests/e2e/command-box.spec.ts` covers the rest.

## Gotchas

- The phone has no keyboard shortcut; the box is a desktop feature.
- Chromium's first Escape in a search field clears it rather than closing the dialog.
