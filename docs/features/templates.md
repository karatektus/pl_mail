# Templates

A template is a message you write once and insert while composing: the reminder, the "thanks,
received", the reply you have typed forty times. They live at **Settings → Templates**, and the
compose window has a button that inserts one.

## The tree

The left of the section is a tree of what you have.

- **At the top**, templates that belong to no folder. They are offered whichever address you are
  writing from.
- **One folder per mail account.** These are always there, empty or not, and you cannot rename or
  delete them: they are your accounts, drawn as folders. Add an account and its folder appears;
  there is nothing to set up.
- **Folders you make yourself**, inside an account, inside one another, or beside the accounts at
  the top level.

**New template** and **New folder** above the tree create at the top level. Hovering a folder shows
the same two buttons for creating *inside* it, and, on a folder you made, rename and delete.

Filing a template under an account says where you usually want it, not where it is allowed. Every
template can be inserted from every address; the account's own are listed first.

A folder you made can be renamed and deleted but not moved. To move its contents, change each
template's **Folder** in the editor.

## Writing a template

Selecting a template, or creating one, opens the editor beside the tree.

| Field | |
|---|---|
| **Name** | What it is called in the tree and in the compose window's list. Never sent. |
| **Folder** | Where it is filed. **No folder** is the top level. |
| **Subject** | Optional. Inserting the template fills the subject line **only if it is still empty**, so a template dropped into a reply does not rename the conversation. |
| **Message** | The text, with the same formatting bar as the signature editor. |

**Save** stores it and closes the editor; the tree is where you see that it worked. **Duplicate**
makes a second copy beside the first, named "Copy of …". **Preview** shows what the template on
screen would insert today for a sample recipient, without saving it.

## Variables

A variable is a part of the message that is filled in when the template is inserted. **Insert
variable** puts one where the caret is, in the subject or in the message, and it appears as a chip.

| Variable | Becomes |
|---|---|
| **First name** | The first recipient's first name |
| **Full name** | The first recipient's name |
| **Recipient's address** | The first recipient's email address |
| **Your name** | The name of the account you are writing from |
| **Your address** | The exact address in **From**, alias included |
| **Signature** | A signature — see below |
| **Date** | A date relative to the day you insert the template — see below |

"The first recipient" is the first address in **To**. Cc and Bcc are not consulted.

### Dates

Click a date chip to set it. A date has an **offset** — a number of days, weeks or months before or
after the day the template is inserted — and a **format**:

| Format | In English | In German |
|---|---|---|
| **Short** | 10/16/26 | 16.10.26 |
| **Medium** | Oct 16, 2026 | 16.10.2026 |
| **Long** | October 16, 2026 | 16. Oktober 2026 |
| **Full** | Friday, October 16, 2026 | Freitag, 16. Oktober 2026 |
| **Weekday** | Friday | Freitag |
| **ISO** | 2026-10-16 | 2026-10-16 |
| **Custom pattern** | whatever the pattern says | |

The four named styles follow the language plMail is set to, so one template reads correctly in
both. A custom pattern is written with `d` for the day, `M` for the month, `y` for the year and `E`
for the weekday: `dd.MM.yyyy` gives 16.10.2026, `EEEE d MMMM` gives Friday 16 October. The line
under the controls writes out today's result as you change them.

Each chip has its own settings, so one template can say "the invoice from 25 September" and "due by
2026-10-16" side by side. "Today" is today in your own time zone.

### Signature

Click a signature chip to choose which signature it stands for.

- **Automatic** is the signature of the address in **From**, the same one the compose window would
  have used. It keeps following **From** after the template is inserted.
- **A named signature** — one of your accounts', or one of the addresses that has a signature of
  its own — is used whichever address you write from, and stays when you change **From**.

Either way the message ends up with one signature: a template that brings one replaces the
signature the window opened with instead of adding a second. A template with no signature variable
leaves the window's signature alone.

## Inserting a template

The compose window's toolbar has **Insert template**, beside **Insert signature**. It opens a list:
the templates of the account you are writing from, then the ones at the top level, then everything
else. Type in the field to narrow it by name. Pointing at a template shows its subject and text on
the right, with the variables still as chips.

Choosing one puts it in the message where the caret is. An empty line is replaced by the template;
a line with writing on it gets the template below it. Dates, your name and address, and the
signature are filled in at that moment and are ordinary text from then on — edit them as you like.

### When there is no recipient yet

A template is often picked before anyone is addressed. The recipient variables then stay in the
message as placeholders, shown as chips reading **First name**, **Full name** or **Recipient's
address**, and are filled in the moment a recipient is added to **To**. Once filled they are text
and do not change again if you swap the recipient.

A placeholder stays open when the recipient cannot answer it. That is usually a first name: an
address you typed has no name attached, and plMail does not guess one from the part before the `@`.
Pick the person from the suggestions, or type the name over the placeholder.

**Send asks first** if a placeholder is still open, the same way it asks about a missing subject.

### Saving a message as a template

**Save this message as a template**, at the foot of the list, keeps what you have written. It is
named after its subject and filed under the account you are writing from; rename or move it in
Settings. The quoted original under a reply is left out, and the signature is saved as a
**Signature** variable, not as today's signature in fixed text.

## Where to read further

- [Mail](mail.md) — the compose window, signatures, and what Send checks before it sends.
- [Accounts and aliases](accounts.md) — the accounts the tree mirrors, and sending addresses.
- [Appearance](appearance.md) — the language setting the date formats follow.

## Things that bite

**Removing a mail account keeps its templates.** The account's folder goes, and so do the folders
you made inside it; the templates in them move to the top level. Deleting a folder does the same
one level up: its templates move to wherever the folder was.

**A typed address has no first name.** With `dana@example.org` typed into **To**, **Recipient's
address** is filled in and **First name** is not. This is deliberate — "Hi dana.whitfield," is
worse than a placeholder — and Send will ask before the message leaves.

**If you send anyway, the placeholder's words are sent.** A message sent with **First name** still
open reads "Hi First name,". The question before sending is the only thing between that and the
recipient.

**The subject only fills an empty subject line.** Inserting a second template into the same message
does not replace the first one's subject, and neither does inserting one into a reply.

**A named signature is read when the template is inserted, not when it is written.** Change that
signature in Settings → Signatures and the template uses the new one. Remove the account it belongs
to and the template falls back to **Automatic**.

**Braces that are not a variable are left alone.** Variables are stored as text such as
`{{recipient.first_name}}`. Typing that into a template by hand makes a variable; typing
`{{anything else}}` is just text and is inserted as written.

**The apps may not show templates yet.** The server offers them to JMAP clients, but each app has to
add the screens. Until one does, templates are written and inserted in the web interface; what you
write there will be in the app when it catches up.
