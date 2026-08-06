# Appearance

**Settings → Appearance** decides what plMail looks like for you and nobody else. Every control
applies as you touch it and saves itself — there is no Save button on this page.

![Dark mode](../screenshots/inbox-dark.png)

## The two axes

The theme picks the palette; the layout picks how it is painted. They are independent, which is why
they are two controls rather than one long list.

**Theme** offers **System**, **Light**, **Paper**, **Dark**, **Nord**, **Dusk** and **Solar**, each
shown as a swatch of its own colours. A new account starts on **Paper** rather than System: "follow
the OS" resolves to whichever of plain white or plain dark the machine happens to prefer, which are
the two least considered palettes here. Picking System is still there for anyone who wants the
operating system to decide, and it is resolved in the browser so the page never flashes the wrong
one on load.

**Layout** is **Flat** or **Boxed**. Flat puts the top bar and sidebar straight on the background
and keeps only the main pane boxed; Boxed floats every panel as a card. Choosing one moves the
sliders below it to that layout's own numbers, so the two never end up describing a state neither
layout would produce. Flat is the default.

## The rest of the controls

| Section | What it sets |
|---|---|
| **Main pane** | A background tint and opacity for the content area on its own, or **Match glass opacity** to follow the panels |
| **Glass** | **Opacity**, **Blur**, **Corner radius** and **Background dimming** — how much of the background shows through your panels |
| **Text** | Text colour plus **Muted** and **Faint**, or **Auto-derive** to have those two worked out from the main one |
| **Accent colour** | The single highlight colour, as a hex value |
| **Density** | **Comfortable**, **Cosy** or **Compact** — row height and spacing |
| **Background** | **Theme default**, one of eight supplied images, or **Upload image** |

Each numeric control is bounded, so a value typed or imported from outside the sliders is clamped
rather than honoured: opacity between 0.15 and 1, blur between 0 and 60 pixels, corner radius
between 0 and 2 rem, background dimming between 0 and 0.7.

The accent colour has to be a six-digit hex value with a leading `#`. Anything else falls back to
the default rather than being stored, and the same is true of the three text colours and the main
pane tint, which become "unset".

## Backgrounds

**Upload image** accepts JPEG, PNG and WebP. The file is stored per user, and uploading a new one
deletes the previous one — there is no gallery of past backgrounds.

Choosing a photo or a supplied image raises the panel opacity floor to 0.45 whatever the slider
says, in both the panels and the main pane. Below that, text on top of a photograph stops being
readable, and a legible interface is worth more than the last of the transparency.

## Export and import

**Export theme** downloads `plmail-theme.json`. **Import theme** takes one back. **Reset to
defaults** puts everything back where a new account starts.

The export carries the version, the theme, the layout, the accent, all four glass numbers, the
density, the background choice, the text colours and the main pane settings. It deliberately does
**not** carry your uploaded background image: a filename on someone else's install means nothing, so
a custom background exports as **Theme default**.

Import checks the version and refuses a file that is not version 1. Anything in the payload that is
not a value plMail recognises is ignored rather than stored, and a layout in the file is applied
before the individual numbers, so an export written before a control existed still lands somewhere
sensible.

## Language

**Settings → General → Language** sets the language the interface is shown in. Your mail is left
exactly as it was written — nothing is translated, and nothing is rewritten.

plMail ships **English**, **Deutsch** and **Pirate English**. Changing it reloads the page rather
than patching it, because every string on screen has to be re-rendered.

The same section carries **Time zone**, which decides the clock times and dates you are shown. It
too rewrites nothing — the same instant is simply read on your own clock — and it can be left on the
server default.

Under it, **Clock** decides whether those times are written on a twelve- or a twenty-four-hour
clock: `2:30 pm` or `14:30`. It applies everywhere a time appears — the mail list, a thread, a
calendar chip, the agenda, the day grid's hour axis — because a setting honoured in most places and
not in one reads as a bug rather than as an option.

Its default is **Follow your language**, which is what everyone is on until they change it: German
reads 14:30, English reads 2:30 pm. That is a real state and not a value in disguise — leave it
there and switching language switches the clock with it. Choosing one of the two explicitly pins it,
whatever the language later becomes.

## Where to read further

- [Mail](mail.md) — the lists and panes these settings paint.
- [Other clients](clients.md) — a third-party app has its own appearance; this is the web UI only.
- [Client development](../CLIENT_DEVELOPMENT.md) — the two-axis model, its semantic colour tokens,
  and how to reproduce them, for anyone building a client that should look like plMail.

## Things that bite

**A custom background does not survive an export.** It is excluded on purpose, since the file lives
on this install and a filename would not resolve anywhere else. An import of your own export
therefore leaves you on the theme default until you upload the image again.

**The opacity slider stops mattering under a photograph.** Anything below 0.45 is silently raised
while a non-theme background is in use. The slider still moves; the rendering does not follow it all
the way down.

**Picking a layout overwrites the glass sliders.** That is what the layout control is — a preset for
those numbers. Set the layout first, then the numbers, not the other way round.

**A hex value that is not six digits is not an error, it is a fallback.** The accent silently
returns to the default and the three text colours silently become unset, so a typo looks like the
control having no effect.

**Import refuses anything that is not version 1.** There is no migration of older or newer files;
the answer is a rejection rather than a partial apply.

**Appearance is per user, not per device.** There is no way to have the dark theme on the phone and
the light one on the desktop except by choosing **System** and letting each device decide.
