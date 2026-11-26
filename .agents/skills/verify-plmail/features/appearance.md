# Appearance

Settings → Appearance changes how all of plMail looks: the theme (light, dark and dozens of named ones), density, typography, the sidebar and the app icon. A change applies at once and stays for that user.

## Sub-features

- `appearance-theme` picks a theme and recolours every page.
- `appearance-density` changes row height and type size.
- `appearance-reset` puts the defaults back.

## How to get to it (user POV)

- The user menu (avatar, top right) → Settings → Appearance, or `/settings/appearance`.
- The command box: type `appearance` or a theme's name.

## Driving it with Playwright

Preconditions:

- The demo user is on the default theme.

- **Open.** `page.goto("/settings/appearance")`. `[data-controller="settings--appearance"]` is visible.
- **Theme.** Click a `[data-theme-name]` button in that panel. `html` takes its `data-theme`, and `/mail/inbox` renders in it.
- **Reset.** The panel's `getByRole("button", { name: /reset/i }).first()` restores the default.
- **Proof.** `shot` the inbox before and after the change. For a change that can look different by theme, shoot it in a light and a dark theme.

## Gotchas

- The choice is saved for the user, so it carries into every later test in the run. Reset it at the end of the spec.
- `tests/e2e/appearance/` holds contrast checks for every theme; run them with `--e2e tests/e2e/appearance` after a colour change.
