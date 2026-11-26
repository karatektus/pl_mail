# Forward to an agent

An agent is anything that reads email and acts on it: a bot, an assistant, a relay. **Forward to
agent** sends the email you are reading to one of yours with a note from you, in one move. It sits
beside **Forward** under an open conversation and in a message's **⋮** menu, and `Shift`+`f` opens
it from the keyboard.

The dialog opens with the note focused and your first agent picked. Write what you want, such as
"what do you think of this?", and press Ctrl+Enter (⌘+Enter on a Mac), or **Send to …**. `Alt`+`1`
to `9` (Option on a Mac) picks another agent without leaving the note.

The forward is the one **Forward** would make: from the account the email is in, with your note
above the forwarded message and its attachments carried along. It waits ten seconds first, like any
send, and **Undo** on the notice opens it in a compose window instead. Unlike a forward from the
compose window, its plain-text part carries the forwarded message as well as your note, so an agent
that reads only plain text still sees the email.

## Your agents

**Settings → Compose → Forward to agent** lists them: a name and an address each, up to nine, in the
order the dialog shows them. Adding an address that is already on the list renames that agent. The
dialog only sends to an address on this list.

## Things that bite

- **Encrypted or signed mail goes through Forward.** Only the compose window can decrypt an
  encrypted email's quote, and only it can sign or encrypt what you send. So the dialog sends
  nothing when the email is encrypted, when you sign everything you send, or when the address it
  goes from is set to always encrypt, or when a default Bcc has a key or is set to always encrypt;
  and an agent whose address has a key, or is set to always encrypt or sign, is shown but cannot be
  picked. Use **Forward** for those.
- **A missing attachment stops the send.** An attachment over 25 MiB, or one that cannot be
  fetched, is not copied. The forward then waits in Drafts instead of going out without it.
- **After Undo it is an ordinary forward.** Sent again from the compose window, its plain-text part
  is your note alone, as any forward's is; the HTML part still carries the email.
- **A receive-only account cannot send.** The forward then goes from the account a new message
  would use, with that account's default Bcc.
