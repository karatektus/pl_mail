---
version: alpha
name: plMail
description: >-
  Self-hosted mail and calendar, drawn like correspondence on warm paper: one
  flat cream sheet, soft near-black ink, pencil-weight hairlines instead of
  shadows, and a single muted clay accent. The token values are the default
  Paper theme with the Flat layout; every other theme restates the same roles.
colors:
  # The spec's conventional names, pointing at plMail's own. The code says `accent`.
  primary: "{colors.accent}"
  on-primary: "{colors.accent-ink}"

  # Surfaces. In code, raised / hover / sunken / line and the field tokens are
  # ink (or white) at a low alpha; the hex here is that result flattened onto
  # `surface`, for reference only.
  app-bg: "#F2EFE7"
  surface: "#F7F5EF"
  raised: "#EFEDE7"
  hover: "#F0EEE8"
  sunken: "#EFEDE7"
  line: "#E4E2DC"

  # Ink, strongest first: what it is, what it is about, its metadata, its furniture.
  ink: "#232220"
  ink-soft: "#524E48"
  ink-muted: "#5F5B54"
  ink-faint: "#716C64"

  # The one accent. It is a user setting in the app: use the role, never the hex.
  accent: "#7D6B4F"
  accent-strong: "#63553E"
  accent-soft: "#EBE7DF"
  accent-ink: "#FFFFFF"
  # The accent as TEXT is derived from whatever accent is set, so it reads on every
  # surface (see Colors). This is Paper's clay run through that derivation.
  accent-text: "#685841"

  field: "#FCFBF9"
  field-border: "#D7D4CB"

  danger: "#C12020"
  danger-soft: "#F3E4DE"
  warning: "#995203"
  success: "#0D7433"
  info: "#56667E"
  info-soft: "#EAEAE6"

  inverse: "#2D2B28"
  inverse-ink: "#F7F5EF"

  # The sheet a message body is rendered on. Light in every theme, dark ones included.
  sheet: "#F7F5EF"
  sheet-ink: "#232220"
  sheet-link: "#1D4ED8"

typography:
  headline-lg:
    fontFamily: &sans 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
    fontSize: 24px
    fontWeight: 600
    lineHeight: 32px
    letterSpacing: -0.025em
  headline-md:
    fontFamily: *sans
    fontSize: 20px
    fontWeight: 600
    lineHeight: 28px
  title:
    fontFamily: *sans
    fontSize: 16px
    fontWeight: 600
    lineHeight: 24px
  body-md:
    fontFamily: *sans
    fontSize: 14px
    fontWeight: 400
    lineHeight: 20px
  body-strong:
    fontFamily: *sans
    fontSize: 14px
    fontWeight: 600
    lineHeight: 20px
  body-reading:
    fontFamily: *sans
    fontSize: 14px
    fontWeight: 400
    lineHeight: 1.625
  label-md:
    fontFamily: *sans
    fontSize: 14px
    fontWeight: 500
    lineHeight: 20px
  label-sm:
    fontFamily: *sans
    fontSize: 12px
    fontWeight: 500
    lineHeight: 16px
  caption:
    fontFamily: *sans
    fontSize: 12px
    fontWeight: 400
    lineHeight: 16px
  micro:
    fontFamily: *sans
    fontSize: 11px
    fontWeight: 400
    lineHeight: 1.35
  overline:
    fontFamily: *sans
    fontSize: 11px
    fontWeight: 600
    lineHeight: 16px
    letterSpacing: 0.05em
  badge:
    fontFamily: *sans
    fontSize: 10px
    fontWeight: 500
    lineHeight: 14px
  mono:
    fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace'
    fontSize: 14px
    fontWeight: 400
    lineHeight: 20px

rounded:
  none: 0px
  sm: 4px
  control: 8px
  pane: 12px
  full: 9999px

spacing:
  unit: 4px
  xs: 4px
  sm: 8px
  md: 12px
  lg: 16px
  xl: 24px
  gutter: 12px
  row-y: 8px
  list-row-y: 10px
  reading-y: 16px
  pane-header: 44px
  control-sm: 32px
  control-md: 36px
  avatar: 36px
  sidebar-rail: 56px
  dialog-width: 448px
  breakpoint-md: 768px
  breakpoint-lg: 1024px

components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.accent-ink}"
    typography: "{typography.label-md}"
    rounded: "{rounded.control}"
    height: "{spacing.control-md}"
    padding: 0 16px
  button-primary-hover:
    backgroundColor: "{colors.accent-strong}"
    textColor: "{colors.accent-ink}"
  button-primary-sm:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.accent-ink}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.control}"
    height: "{spacing.control-sm}"
    padding: 0 10px
  button-secondary:
    backgroundColor: "{colors.field}"
    textColor: "{colors.ink-soft}"
    typography: "{typography.label-md}"
    rounded: "{rounded.control}"
    height: "{spacing.control-sm}"
    padding: 0 14px
  button-secondary-hover:
    backgroundColor: "{colors.hover}"
    textColor: "{colors.ink-soft}"
  button-quiet:
    backgroundColor: "#E6E4DE"
    textColor: "{colors.ink-soft}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.control}"
    height: "{spacing.control-sm}"
    padding: 0 10px
  button-quiet-hover:
    backgroundColor: "#D9D7D2"
    textColor: "{colors.ink-soft}"
  button-quiet-destructive-hover:
    backgroundColor: "{colors.danger-soft}"
    textColor: "{colors.danger}"
  button-icon:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink-faint}"
    rounded: "{rounded.control}"
    size: "{spacing.control-sm}"
  button-icon-hover:
    backgroundColor: "{colors.hover}"
    textColor: "{colors.ink}"
  input-field:
    backgroundColor: "{colors.field}"
    textColor: "{colors.ink}"
    typography: "{typography.body-md}"
    rounded: "{rounded.control}"
    height: "{spacing.control-md}"
    padding: 0 12px
  chip-filter:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink-muted}"
    typography: "{typography.micro}"
    rounded: "{rounded.control}"
    height: 24px
    padding: 0 8px
  chip-filter-active:
    backgroundColor: "#EDEAE2"
    textColor: "{colors.accent-text}"
  label-chip:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink-muted}"
    typography: "{typography.micro}"
    rounded: "{rounded.full}"
    padding: 1px 8px
  count-pill:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.accent-ink}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.full}"
    padding: 2px 6px
  badge-danger:
    backgroundColor: "{colors.danger-soft}"
    textColor: "{colors.danger}"
    typography: "{typography.badge}"
    rounded: "{rounded.full}"
    padding: 2px 8px
  badge-warning:
    backgroundColor: "#EFE8DC"
    textColor: "{colors.warning}"
    typography: "{typography.badge}"
    rounded: "{rounded.full}"
    padding: 2px 8px
  badge-success:
    backgroundColor: "#E4EBE0"
    textColor: "{colors.success}"
    typography: "{typography.badge}"
    rounded: "{rounded.full}"
    padding: 2px 8px
  badge-info:
    backgroundColor: "{colors.info-soft}"
    textColor: "{colors.info}"
    typography: "{typography.badge}"
    rounded: "{rounded.full}"
    padding: 2px 8px
  badge-neutral:
    backgroundColor: "#E6E4DE"
    textColor: "{colors.ink-soft}"
    typography: "{typography.badge}"
    rounded: "{rounded.full}"
    padding: 2px 8px
  tag-neutral:
    backgroundColor: "{colors.raised}"
    textColor: "{colors.ink-muted}"
    typography: "{typography.micro}"
    rounded: "{rounded.full}"
    padding: 2px 8px
  inline-code:
    backgroundColor: "{colors.sunken}"
    textColor: "{colors.ink}"
    typography: "{typography.mono}"
    rounded: "{rounded.sm}"
    padding: 0 4px
  divider:
    backgroundColor: "{colors.line}"
    height: 1px
  nav-row:
    backgroundColor: "{colors.app-bg}"
    textColor: "{colors.ink-muted}"
    typography: "{typography.body-md}"
    rounded: "{rounded.full}"
    height: 36px
    padding: 8px 12px
  nav-row-hover:
    backgroundColor: "{colors.hover}"
  nav-row-active:
    backgroundColor: "{colors.accent-soft}"
    textColor: "{colors.accent-text}"
    typography: "{typography.label-md}"
  list-row:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink-muted}"
    typography: "{typography.body-md}"
    padding: 10px 16px 10px 12px
  list-row-unread:
    backgroundColor: "#FAF9F5"
    textColor: "{colors.ink}"
    typography: "{typography.body-strong}"
  list-row-hover:
    backgroundColor: "#FCFBF9"
  avatar:
    backgroundColor: "#2F6B52"
    textColor: "#FFFFFF"
    typography: "{typography.body-strong}"
    rounded: "{rounded.full}"
    size: "{spacing.avatar}"
  pane:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.pane}"
  menu:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink-muted}"
    typography: "{typography.body-md}"
    rounded: "{rounded.pane}"
    padding: 4px 0
  menu-row-hover:
    backgroundColor: "{colors.hover}"
    textColor: "{colors.ink}"
  tooltip:
    backgroundColor: "{colors.inverse}"
    textColor: "{colors.inverse-ink}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.control}"
    padding: 6px 10px
  dialog:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.pane}"
    width: "{spacing.dialog-width}"
  toast:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.label-md}"
    rounded: "{rounded.pane}"
    padding: 14px 16px
  alert-danger:
    backgroundColor: "{colors.danger-soft}"
    textColor: "{colors.danger}"
    typography: "{typography.body-md}"
    rounded: "{rounded.control}"
    padding: 12px 14px
  mail-sheet:
    backgroundColor: "{colors.sheet}"
    textColor: "{colors.sheet-ink}"
    typography: "{typography.body-reading}"
  mail-sheet-link:
    backgroundColor: "{colors.sheet}"
    textColor: "{colors.sheet-link}"
  search-hit:
    backgroundColor: "#DCD7CC"
    textColor: "{colors.ink}"
    rounded: 3px
  new-dot:
    backgroundColor: "{colors.accent}"
    rounded: "{rounded.full}"
    size: 6px
---

# plMail

## Overview

plMail is a self-hosted mail and calendar client. The people who use it run the server themselves
and keep the app open all day in place of Gmail. It is a **fortieth-time interface**: whatever a
screen does, it will do forty more times before lunch, so it has to stay calm, fast and quiet.

It should look like **correspondence on good paper**: a sheet of warm, uncoated cream; soft
near-black ink, never pure black; rules drawn in pencil rather than boxes drawn in pen; and one
muted clay accent, reserved for the thing you are meant to press. The window is a single material.
The app background and the panes are two close shades of the same cream, so the mail list reads as
the page itself rather than as a card floating over a picture. Nothing glows or shouts, and the page
is flat: no gradient, no texture.

The standard of finish is the Claude apps: calm, warm, typographic and unhurried. plMail matches
their care, not their look. Gmail is the benchmark for what the app can *do*, never for how it
*looks*.

Four traits follow from the audience:

- **Dense, not cramped.** Mail is a list you scan. Rows are tight at every density and still meet
  their touch floors.
- **Honest.** A state says only what is true. The live-update dot stays neutral until a connection
  exists, a loading model gets breathing dots rather than a fake progress bar, and "no results"
  mentions the sync window when that is the reason.
- **Self-contained.** No webfont, no CDN, no runtime request to a third party. Anything the app
  draws, it ships: the icon font and the emoji font are vendored.
- **Bilingual from the first line.** English and German, with German using the informal *du*.
  German runs about 30% longer, and layouts are designed at that length.

When no rule covers a decision, pick the quieter option: a hairline over a box, weight over size,
ink over colour, stillness over movement.

The tokens in this file describe **Paper** with the **Flat** layout, which is what a new account
starts on. The app ships about forty themes and a set of appearance settings. None of them adds a
role; each one restates the same roles with different values (see *Themes* below).

## Colors

The palette is **roles, not colours**. Every colour on screen is one of the roles below, and each
role is a Tailwind utility (`bg-surface`, `text-ink-muted`, `border-line`, `bg-hover`,
`ring-accent`, …) declared in `@theme inline` in `assets/styles/app.css`. A theme is a block of RGB
channels, so switching theme re-resolves everything and no component knows which theme it is in.
The hex values here are Paper's. Hairlines, hover, raised, sunken and the fields are ink (or white)
at a low alpha in code, and the hex is the flattened result. **Never write a hex, `rgb()` or a
Tailwind palette colour (`zinc-400`, `blue-600`) into a template.**

**Surfaces**, from the back:

- **App background** {colors.app-bg}: the desk the sheet lies on. In the Flat layout the top bar
  and the sidebar sit directly on it, with no card of their own.
- **Surface** {colors.surface}: the sheet. Panes, menus, dialogs and toasts use it. The step down to
  the app background is deliberately small, so the window reads as one material.
- **Raised** {colors.raised}, **hover** {colors.hover} and **sunken** {colors.sunken}: ink at
  3.5–4%. These are surface shifts, not colours. Hover is the lightest step there is, and it is how
  a row answers the pointer.
- **Line** {colors.line}: the hairline, ink at 9%. It divides rows, separates a header from its body
  and edges a pane. It is a rule, not a frame.

**Ink** has four steps. They form a hierarchy of meaning rather than of size:

- **Ink** {colors.ink} is what the message is: unread senders and subjects, titles, anything being
  read.
- **Ink soft** {colors.ink-soft} is what it is about: secondary lines and button labels.
- **Ink muted** {colors.ink-muted} marks read rows, resting navigation and descriptions.
- **Ink faint** {colors.ink-faint} is furniture: timestamps, counts, placeholders, overlines and
  resting icons.

**The accent is clay**: {colors.accent}, deepening to {colors.accent-strong} on hover and press (the
accent mixed 15% toward black in oklab). Its tint is {colors.accent-soft} (10%), and white
{colors.accent-ink} goes on top of it. The accent has few jobs:

- the Compose button, and the one primary action in a view;
- the active navigation row (a soft pill with accent text);
- the unread count pill and the new-mail dot;
- focus rings (`ring-2 ring-accent`) and the checked state of a control (a checkbox, a selected
  row's avatar tick, a pressed filter chip);
- links in the composer;
- the search-hit wash;
- live drop targets during a drag;
- the unread bar at Strong emphasis.

It is never a large filled area. The accent is also a **user setting**: each theme seeds its own,
AppearanceRenderer writes the user's choice inline on `<html>`, and a template that uses the clay
hex breaks the moment someone picks another colour.

**As text, the accent is derived.** `text-accent` does not paint the accent itself but
{colors.accent-text}: the accent mixed toward black in oklab until its lightness is at most 0.47 on
a light scheme, or toward white until it is at least 0.70 on a dark one. That keeps the hue, and an
accent that already reads is left alone. The bounds are the loosest that hold 4.5:1 for every
accent a user can pick, on the surface, the page, a hovered row and the accent's own 10–15% washes
(`AccentTextContrastTest` sweeps the RGB cube). Paper's clay reads as {colors.accent-text} and Dark's
deep green as `#88A79A`. Fills, rings and the checked state keep the accent itself: a fill has to be
deep enough for its ink, and text has to be light enough for its surface, and in a dark theme no
one colour is both. A rule that sets a colour itself writes `var(--accent-text)`, never
`rgb(var(--rgb-accent))`. The message sheet always takes the light-scheme text.

**Status colours** come in small doses: {colors.danger} danger, {colors.warning} warning,
{colors.success} success, and {colors.info} info. Info is a slate, not a link blue. They are for an
icon, a badge (`badge-*`, a tint at 8%) or an alert box (`alert-danger` / `alert-info`: an 8% fill
with a 20–25% border). A destructive control stays quiet until hover, when it turns danger on
`danger-soft`.

They are text as often as they are colour, so they meet the ink's 4.5:1 on the surface, on the page
and on a wash of themselves up to 12% (the onboarding pills; badges and alerts wash at 8%). That is
why no light theme keeps the Tailwind 600s it started with: each is the old colour mixed toward
black in oklab (toward white on a dark theme), keeping its hue, only as far as the floor needs. The
wash is measured as a browser paints it, eight-bit alpha and whole steps, which is a shade heavier
than the exact sum, and Chromium's software raster can land one step heavier again.
`ThemeInkContrastTest` holds every theme to all of this, and holds every ink step to 4.5:1 on the
page as well as the surface.

**Inverse** {colors.inverse} with {colors.inverse-ink} is the tooltip bubble: the one thing that
flips the page.

**The message sheet** {colors.sheet} is always light. Mail is written against a white page: senders
set dark text and assume white behind it, so a message rendered on a dark surface is both harsh and
a misrepresentation of what was sent. In a dark theme the reading pane is still light; which light
is up to the theme. The `mail-sheet` utility re-points the channels, so every token utility inside
(`text-ink`, `border-line`, `hover:bg-hover`) resolves to the sheet without knowing it exists. Links
inside a body take {colors.sheet-link}, not the accent, because an accent tuned for a dark theme can
be unreadable on the sheet. Status text takes the sheet's own danger, warning, success and info for
the same reason: a draft, the spam warning, the read-receipt line, "Draft saved" in an inline reply
and the scheduled badge all sit on the sheet, and a dark theme's status colours are lightened for
its near-black surface. `ThemeInkContrastTest` holds the link and all four at 4.5:1 on the sheet,
and the four on a 10% wash of themselves too.

**Palettes that are not roles:**

- A sender's avatar takes one of eight muted tones chosen by address: rust, green, blue, indigo,
  olive, plum, teal and ochre, with a white initial. Dark themes use pastel versions with a dark
  initial. Muted on purpose: forty saturated circles make a chart, not a mailbox.
- A user label takes one of nine colours (gray, red, orange, amber, green, teal, blue, violet, pink).
  It is drawn as an outline plus text, never as a fill.

### Themes

| Role | Paper (default) | Dark |
|:--|:--|:--|
| app-bg | `#F2EFE7` | `#151413` |
| surface | `#F7F5EF` | `#1C1B1A` |
| line | ink at 9% | white at 9% |
| ink | `#232220` | `#D6D1CA` |
| ink-soft | `#524E48` | `#BAB5AE` |
| ink-muted | `#5F5B54` | `#9F9B95` |
| ink-faint | `#716C64` | `#86837E` |
| accent | `#7D6B4F` clay | `#3A6F5C` deep green |
| accent as text | `#685841` | `#88A79A` |
| danger | `#C12020` | `#E28080` |
| sheet | `#F7F5EF` | `#F8FAFC` |

Dark is **a dim room, not a black one**. The floor sits well off pure black, the ink stops well
short of white, the greys are warm (more red than blue), and hairlines rather than lighter panes do
the separating. Pure grey on black makes a dark theme look like a terminal.

- **Every theme block declares every variable.** A theme is `:root` (Light) or
  `:root[data-theme="…"]`, and `ThemeVariableCompletenessTest` fails the build when a block drifts
  from the inventory, so a new role goes into every block at once. The base seven are System, Light,
  Paper, Dark, Nord, Dusk and Solar. The rest, one per logo colourway, came out of a generator that
  no longer exists, and are edited by hand like the others.
- **Layout is a second axis.** It is set independently of the theme. **Flat** is the default: the
  chrome sits on the page and the main pane is the only box. **Boxed** gives floating panes and
  optional glass. Radius, density, text scale, pane opacity and blur, unread emphasis and motion are
  per-user knobs written inline on `<html>`. Build with the variables and utilities, and new UI
  follows every knob automatically.
- **The Android app** (`pl_mail_android`, `:core:designsystem`) uses the same role vocabulary in
  camelCase (`inkSoft`, `accentSoft`, `fieldLine`). Its own Light and Dark are warm neutrals with a
  deep-green accent, and it renders the web's `paper` as its Light.

## Typography

There is **one family: the platform's own interface face** (a `system-ui` stack). Every size is set
in it. The app ships no webfont: a self-hosted install behind a firewall must not depend on a font
CDN, and a 400 KB download to change the shape of the sidebar is a poor trade. Users can switch the
interface to a Grotesk, Serif or Monospace stack, so nothing may depend on the metrics of one face.

**Hierarchy comes from weight and ink, not size.** The interface lives at two sizes: 14px
({typography.body-md}) for content and controls, and 12px ({typography.caption}) for metadata. Only
titles leave that band, and they leave it modestly: a pane title is 20px ({typography.headline-md}),
not a hero.

- **Three weights.** 400 for reading, 500 for labels and controls, 600 for emphasis (unread
  conversations, titles, overlines). Bold (700) essentially never appears.
- **Unread and read** is the defining pair: the same 14px line is 600 in `ink` when unread and 400
  in `ink-muted` once read. Size never changes between them.
- **The overline** ({typography.overline}: 11px, 600, uppercase, 0.05em, `ink-faint`) heads a
  section or a group. The sidebar's LABELS and ACCOUNTS use it at 12px, menu groups at 10px. Never
  on a button, never on a title.
- **Small text** stops at 11px ({typography.micro}) for words: label chips, filter chips, hints.
  10px ({typography.badge}) is for numerals and one-word badges only.
- **Paragraphs** of help or description use {typography.body-reading} (`leading-relaxed`). Tight
  stacks use `leading-snug`.
- **Numbers that change** (a countdown, a table of counts) use `tabular-nums`. Addresses, tokens and
  codes use `font-mono` ({typography.mono}, Tailwind's own stack), which is separate from the
  Monospace *interface* font a user can pick.
- **Everything is rem.** The root is `16px × --app-font-scale` (0.875–1.25), so interface text set
  in px escapes the user's text size. The single exception is the composer: its 14px (16px below
  768px) is pinned because the size is part of the message being sent, and the sender must see what
  the recipient will get.
- **Fields never go below 16px under 768px.** iOS zooms into a smaller focused field and does not
  zoom back.
- **Emoji** use the vendored Noto Color Emoji, and only in the emoji picker and the message being
  written. Everywhere else the reader's system draws them.

## Layout

The shell is a **top bar over three panes**: sidebar, message list, reading pane. A calendar can dock
at the right. It has three positions (mail, split, calendar), and the split needs 1024px or more.

- **Gutter:** {spacing.gutter} between panes and around the shell. Flat drops the left gutter so the
  navigation pills can run to the window edge.
- **One header height:** every pane's header row is {spacing.pane-header} (`--pane-header-h`), so
  headers that sit side by side line up.
- **4px grid.** The spacings in real use are 8 (icon to label, `gap-2`), 12 (`px-3`), 16 (pane
  padding, `px-4`), 6 (`gap-1.5`) and 24 (sections).
- **Density** has three steps, **Comfortable** (default), **Cosy** and **Compact**, and each surface
  (sidebar, list, reading pane) can take its own. Rows read their padding from variables through
  `py-row`, `list-row-y`, `reading-y`, `gap-gutter` and `nav-rows`. Never hardcode a row's padding.
  At Comfortable, a sidebar row is 36px tall, a nested label row 32px, a mail row has
  {spacing.list-row-y} of vertical padding, and a reading block has {spacing.reading-y}.
- **Touch floors:** on a coarse pointer, rows never shrink below their Comfortable height, whatever
  the density. The Android app keeps targets at 48dp or more.
- **Below 768px** the sidebar becomes a drawer, compose goes full-screen, and search collapses to an
  icon that opens a full-width row under the top bar.
- **Container queries, not viewport queries,** for anything inside a pane. A mail row switches
  between stacked and single-line on the *list's* width (`@xl`, 36rem), because a docked calendar can
  make the list narrower than a phone.
- **Nothing scrolls the page sideways.** Panes scroll their own overflow. The shell uses `dvh` and
  honours safe-area insets.
- **Design at German length, at 320px wide, and at text scale 1.25.**

## Elevation & Depth

Depth comes from **tone and hairlines, not shadow**. The page sits a shade below the sheet, a
hairline edges each pane, and a mail row lifts toward white under the pointer.

- **Panes whisper.** The `pane` / `main-pane` shadow is ink at 5% on Paper, kept within the 12px
  gutter so the window edge never clips it. In the Flat layout the chrome has no card and no shadow
  at all.
- **Only what floats gets a real shadow.** Menus and popovers (`popover`), the compose window,
  dialogs and toasts (`pane-flat shadow-float`) sit over content and need to separate from it. A
  dropdown has no backdrop to do that for it.
- **Modal scrim:** black at 50% with a 4px blur, for both the modal and the confirm dialog. It is a
  decision the app makes, not the user's wallpaper scrim.
- **Glass is opt-in.** The Boxed layout and the opacity and blur settings enable it. Floating
  surfaces keep their own higher opacity (`--popover-alpha`, never below 0.5) so words are never read
  through other words. `prefers-reduced-transparency` forces everything opaque.
- **`backdrop-filter` traps `position: fixed`.** Anything that must escape a pane, such as the
  tooltip or the confirm dialog, is rendered at `<body>` level. The message sheet isolates itself
  (`isolation: isolate`) so the blur behind it cannot bleed through.

## Shapes

**Radius belongs to panes, not controls.**

- **`rounded-pane`** ({rounded.pane} by default): every surface the eye reads as a panel. That
  means panes, dialogs, menus, the compose window and toasts. This radius is a user setting from 0 to
  32px (`--app-radius`), so a hardcoded `rounded-xl` on a panel is wrong at every radius but one.
- **`rounded-lg`** ({rounded.control}, fixed): every control. That means buttons, fields, selects,
  filter chips, icon buttons, tooltips and rail tiles. Controls do not grow 32px corners because
  someone likes round panes.
- **`rounded-full`**: avatars, count pills, label chips, status dots, and the right-hand end of a
  navigation row.
- **`rounded`** ({rounded.sm}): checkboxes.
- **Navigation rows** are a pill that runs off the left edge and rounds fully on the right. The shape
  is there at rest; hover and active only change its colour, so a highlight never morphs on the way
  out.

## Components

The recipes below are the app's own. Reuse the named utilities (`pane`, `popover`, `btn-quiet`,
`chip-filter`, `select-field`, `badge-*`, `alert-*`, `mail-sheet`) rather than re-spelling them.

### Buttons

- **Primary** is accent-filled and appears at most once per view: Compose, Send, Save.
  `h-9 px-4 rounded-lg text-sm font-medium bg-accent hover:bg-accent-strong text-accent-ink`. A
  toolbar uses `h-8 px-2.5 text-xs`. Two accent buttons in one form means two primary actions,
  which is one too many.
- **Secondary** has the field look: `h-8 px-3.5 rounded-lg text-sm font-medium text-ink-soft
  bg-field border border-field hover:bg-hover`. Use it for Cancel, and for a step towards the real
  action, such as choosing a file.
- **Quiet** (`btn-quiet`, `h-8 px-2.5 text-xs font-medium`) is a tinted step with no border. Use it
  for the everyday verbs in toolbars.
- **Icon** buttons are a 32px square, `rounded-lg`, resting in `ink-faint` and coming up to `ink` on
  `hover:bg-hover`. Every icon-only button carries a translated `title`, and the app turns that into
  its tooltip.
- **Destructive:** quiet until meant, `hover:text-danger hover:bg-danger-soft`. Anything that cannot
  be undone asks first through the app's confirm dialog (`data-turbo-confirm`, or `confirm.js` for
  fetch-driven actions), never `window.confirm()`.

### Fields

- **Text input** is a filled well rather than an outlined box. The form theme
  (`_layout/form/modal_form_theme.html.twig`) renders it as `block w-full h-9 rounded-lg border
  border-field bg-field px-3 text-sm text-ink placeholder:text-ink-faint shadow-sm
  shadow-zinc-950/5 focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent`.
  `border-field` is the *fill* colour (Tailwind names it after `--color-field`), so the edge is
  carried by the lighter fill and the faint shadow under it. Focus is always the 2px accent ring
  and never a browser outline. That edge is 1.05:1 against the surface (1.11:1 on Dark), under the
  3:1 WCAG 1.4.11 asks of a control's boundary, and the well is kept on purpose: it was weighed on
  2026-09-28 against a 3:1 outline (`#8F897E` on Paper) and against the selects' 1.4:1 edge.
- **Label and help:** a row is `flex flex-col gap-2`, its label `text-[13px] font-medium
  leading-none text-ink-soft` (a required field adds a `text-danger` asterisk), and its help
  `text-[12px] text-ink-faint leading-snug`. Rows stack `space-y-5`.
- **Select:** `select-field`, which does draw an edge, in {colors.field-border}. Tom Select
  progressively replaces it, and its control mirrors `select-field` value for value, so change both
  or neither.
- **Checkbox and radio:** `size-4 rounded accent-accent focus:ring-2 focus:ring-accent` (a radio
  drops `rounded`). `accent-accent` is what colours a native box. `text-accent` does nothing to one
  without the forms plugin, which the app does not load, so a box that carries only `text-accent`
  renders in the browser's default blue. The form theme's `checkbox_widget` and `radio_widget` do
  this for every Symfony form. File inputs are styled once, globally, in the secondary look.

### Chips, pills and badges

- **Filter chip** (`chip-filter`, pressed via `data-active="true"`): 24px tall, 11px, `ink-muted` on
  a hairline. Pressed, it takes the accent's border and an 8% wash, and {colors.accent-text} for
  its text.
- **Label chip:** an outlined pill of 11px text with a 40% border, `max-w-28 truncate`. A row shows
  at most three, then `+N`. Never filled.
- **Count pill:** the unread number in the sidebar. `text-xs font-semibold px-1.5 py-0.5
  rounded-full bg-accent text-accent-ink`. The **new-mail dot** is the same accent at 6px.
- **Badge** (`badge-danger | warning | success | info | neutral`, `text-[10px] font-medium px-2
  py-0.5 rounded-full`): a status word in a list, tinted, never solid.
- **Neutral tag:** `text-[11px] font-medium px-2 py-0.5 rounded-full bg-raised text-ink-muted`, for a
  count or a word beside a heading. Muted, not faint: faint ink was 4.41:1 on the raised step. **Inline code** (a search operator in help text) is
  `bg-sunken px-1 rounded` in a `<code>`.

### Navigation

A sidebar row rests in `ink-muted` and hovers to `bg-hover`. When active it becomes
`bg-accent-soft text-accent font-medium`. The ancestors of the active label take accent text only:
"you are in here", not a second selection. Icons are 16px, labels `text-sm`, and the count pill
sits at the right. Section heads are overlines with a quiet `+` beside them. Collapsed, the sidebar
is a 56px rail of centred icons, and the new-mail dot moves to the icon's corner.

### The mail row

The row is the product. Keep it quiet.

- A **36px avatar disc** that doubles as the checkbox. It turns into an accent disc with a tick on
  hover or when checked.
- **Sender, subject, snippet, chips, time.** In a wide list they share one line, subject and snippet
  together. In a narrow one they stack, and the snippet clamps to the user's 0–2 lines.
- **The row** is `flex items-center gap-3 pl-3 pr-4 list-row-y`. Its padding comes from the density
  variable, never `py-*`.
- **Unread:** sender and subject `font-semibold text-ink`, plus a faint white wash (`row-unread`,
  scaled by the emphasis setting). Strong emphasis adds a 3px accent bar on the leading edge.
  **Read:** sender `font-normal text-ink-muted`, subject `font-normal text-ink-soft`. The snippet
  is `ink-faint` in both, as are the time and counts.
- **Hover lifts the row:** `hover:bg-white/60 dark:hover:bg-white/[0.06]` on the row and
  `hover:shadow-sm` on the `<li>`, while the row's actions appear at its right edge. They are icon
  buttons like any other (`text-ink-faint hover:text-ink hover:bg-hover`), and Delete and Delete
  forever take the destructive hover (`hover:text-danger hover:bg-danger-soft`). Hairlines
  divide the rows. Search hits get an accent wash of 22% and step up to full ink, with no padding,
  so the highlight is never sliced by `truncate`.

### Menus, tooltips, dialogs, toasts

- **Menu:** `popover py-1 min-w-44`, placed `top-full mt-1`. Rows are `w-full flex items-center
  gap-2.5 px-3 py-2 text-sm text-ink-muted hover:bg-hover hover:text-ink`, with a 16px centred icon.
  Group headers are 10px overlines.
- **Tooltip:** one bubble for the whole app, on `<body>`, in inverse colours. It is 12.5px/500 with
  an 8px radius and a caret aimed at the trigger. Give an element a `title` and it gets the tooltip.
- **Confirm dialog:** a `<dialog>`, `min(28rem, 100vw - 2rem)`, `pane-flat shadow-float`, `p-6`.
  It has a {typography.title} heading, the question in `text-sm leading-relaxed text-ink-soft`, and
  Cancel (secondary) and Continue at the bottom right. **Continue is accent, not red**, even though
  every caller is destructive: the red belonged to the control that opened the dialog, and two red
  things beside a quiet Cancel stop being read. The safety is that Cancel holds the focus.
- **Toast:** `pane-flat shadow-float min-w-[280px] max-w-sm border-l-4`, `px-4 py-3.5 text-sm
  font-medium`. The tone lives only in the 4px leading edge and the icon (`border-success` with
  `fa-circle-check`, `border-danger` with `fa-circle-exclamation`, `border-info` with
  `fa-circle-info`); the surface and the text stay neutral. Undo, where there is one (undo send),
  is an underlined accent link inside the toast.
- **Alert** (`alert-danger`, `alert-info`): `rounded-lg px-3.5 py-3 text-sm` with a leading icon, for
  a problem that belongs to a form or a pane rather than to the moment.

### The reading pane and composer

The reading pane is `mail-sheet`, light in every theme. A message body renders in a sandboxed
iframe that carries the sheet across: the theme's sheet colours, 14px/1.55 system type for mail that
sets none, and the sheet's link blue. The sender's HTML is sanitised, never restyled. Message
headers are sticky, and an open header menu raises its own header above the next one.

The composer docks at the bottom right as a floating card on desktop and becomes full-screen below
768px. Its editor keeps its own pinned type, and its links are accent and underlined.

### Live state

- **The stream dot** beside the wordmark is 6px. It is neutral until the first state arrives, then
  green (connected), amber (connecting) or red (offline). It is sized to be findable rather than
  noticeable.
- **A model thinking** shows three dots breathing out of phase. Under reduced motion they stay still
  at a readable opacity. Never a progress bar for work whose progress is unknown.

## Iconography

- **Font Awesome Free 7**, vendored through the importmap. `fa-solid` is the default. `fa-regular`
  is for the lighter outline glyphs (envelope, calendar, clock) and for the "off" half of a toggle,
  such as an unstarred star. Icons are 16px (`w-4`, centred) beside a label, in the label's ink.
  Decorative icons get `aria-hidden="true"`.
- **Inline SVG is the exception.** The inbox category tabs draw Heroicons (outline at rest, solid for
  the tab you are on), and checkboxes draw their tick and dash in a 10×10 viewBox with
  `stroke="currentColor"`.
- **The mark** is "pl", drawn as seven round-capped strokes on a 48-unit grid. It comes in
  colourways. **Berry**, a violet-to-rose sweep, is the default, and the mark follows the theme when
  linked. Always draw it through `_partials/_logo_mark.html.twig` and never redraw its geometry. It
  is the one flourish of colour in the chrome.

## Motion

Quick and purposeful. Movement tells the eye where to look and then gets out of the way, because
past about a quarter second a transition stops reading as "this moved" and starts reading as "I am
waiting for this".

| Token | Value | Used for |
|:--|:--|:--|
| `--motion-fast` | 120ms | exits, which are rare (a modal or a toast leaving) |
| `--motion-base` | 180ms | every entrance (`data-enter`), the dialog scrim |
| `--motion-slow` | 260ms | window-sized entrances (`pop`: the modal); the ceiling |
| `--motion-ease` | `cubic-bezier(0.22, 0.68, 0.32, 1)` | everything: decelerate, never overshoot |
| `--motion-lift` | 6px | how far a `rise` travels |
| `--motion-row-base` | 600ms, 48px, overshooting | a new mail arriving, the one exception |

- **Opt in with an attribute**, not a keyframe: `data-enter="fade | rise | pop | slide-down |
  slide-right"`, and `data-enter-stagger` on a container. Every timing lives in `motion.css` and the
  `MotionLevel` enum. Never invent a duration in a component.
- **New mail is the only animation that carries information**, so it alone may take 600ms and
  overshoot. Nothing else bounces. A spring reads as playful the first time and slow the fortieth.
- **Exits are rare.** An exit animation is time between asking for something and getting it, so
  archiving a mail plays none. Only a surface you were looking at, such as a modal or a toast,
  animates out.
- **Motion levels.** At Full things travel and fade. At Minimal they only fade, faster. At None
  every duration is zero. `prefers-reduced-motion` overrules the setting in both directions without
  asking.

## Do's and Don'ts

- **Do** use a role utility for every colour. **Don't** write a hex, `rgb()` or a Tailwind palette
  colour (`text-zinc-400`, `bg-blue-600`) in a template. It is right in one theme out of forty.
- **Do** keep the accent scarce: one primary button per view, plus the few markers listed under
  *Colors*. **Don't** fill a banner, a header or a large area with it.
- **Do** build hierarchy from weight and the ink ramp. **Don't** reach for a bigger size or a new
  colour to make something important.
- **Do** separate with a hairline or a surface step. **Don't** put a shadow on anything that doesn't
  float, or nest boxes inside boxes.
- **Do** round panels with `rounded-pane` and controls with `rounded-lg`. **Don't** give a panel a
  fixed radius, or a button the pane radius.
- **Do** keep every text pair at WCAG AA (4.5:1) in both a light and a dark theme, and on the app
  background as well as on the surface. Check it; don't trust your eye.
- **Do** render message bodies on the light `mail-sheet` in every theme. **Don't** restyle a
  sender's HTML: the frame only supplies defaults for mail that sets none.
- **Do** put every visible string in the translation files, in English, German (informal *du*) and
  the pirate locale. **Don't** hardcode English, even in a `title` or an `aria-label`.
- **Do** read density, radius, text scale and motion from their variables. **Don't** hardcode a
  row's padding, a panel's radius or an animation's duration.
- **Do** keep a confirm dialog's button accent and let Cancel hold the focus. **Don't** paint it
  red: the red belongs to the control that opened it.
- **Don't** add a webfont, a CDN link or any other runtime request to a third party.
- **Don't** give a component a gradient, a glow, a coloured shadow or a bouncing entrance. A
  theme's page background is the theme's business; a component's surface is always flat. The
  quietest honest version is the right one.
