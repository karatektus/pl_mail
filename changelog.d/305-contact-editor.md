<!-- highlight -->
**A simpler contact editor, and new contacts from whatever you paste.** **New contact** is now one
box: type or paste a name, an email signature, a `Name <address>` line, an address or a vCard, and
the details it finds appear below it, each one easy to untick, retype or give another type with one
click (a work number that is a home number). Lines it cannot place are asked about instead of
guessed. The editor shows the name as one field whose parts fill themselves, email and phone rows
that appear as you type, and only the kinds of detail a contact has, with the rest a click away; a
bar at the bottom says what changed. **Paste details** in the editor compares pasted text with the
contact before anything changes: new details ticked, ones that differ left for you to decide.

<!-- highlight -->
**Improve with AI, for contacts.** With the new **Contact help** switch on (Admin → AI, off by
default), quick add can give pasted text a second reading by the AI, with what it changed marked
and undoable, and the editor can suggest updates to a contact from the signature of the newest mail
they sent you, each with its reason. Only the signature block of that mail is sent, never its body,
and nothing is saved until you tick it and save. Everyone can switch it off for themselves under
Settings → Assistant.

**Migration:** one migration adds the switch's two columns (`ai_settings.contact_help_enabled`,
`"user".ai_contact_help_off`), both off by default.
