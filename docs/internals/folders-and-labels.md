# Folders and labels

How plMail tells a place from a tag, what the code does with each on a plain IMAP account, and the
rules the folder actions keep. The design was agreed in
[#221](https://github.com/karatektus/pl_mail/issues/221); this page is the part of it that is
built. For using it, see [Folders](../features/accounts.md#folders) and
[Labels](../features/mail.md#labels).

## The model

**A folder is a place.** Every message is in exactly one folder of its account. The folders are the
account's real tree on the server, mirrored as it is, however deep and however oddly named. Moving
mail means moving it between folders, and making, renaming and deleting a folder are actions on the
folder.

**A label is a tag.** A message can carry any number of them, and putting one on or taking one off
never moves mail.

The principle behind both: **plMail adapts to the organisation the person already has and never
quietly reshapes it.** Somebody who spent a decade building a folder tree should find it in plMail
untouched; turning folders into labels, or labels into folders, is something they ask for, not a
side effect.

The protocols split the same way. IMAP has mailboxes and keywords (flags), Outlook has folders and
categories, and JMAP (RFC 8621) has `mailboxIds` and `keywords` — which matters because plMail
serves JMAP itself. Only Gmail has labels and nothing else.

## Where the code is today

The data model already separates the two; the interface does not yet.

- A `Label` belongs to the user, not to an account. A `LabelBinding` materialises it on one
  account and carries what it is there: an IMAP folder (`mailbox`), a Gmail label, an Exchange
  folder or an Outlook category.
- A `Mailbox` row is an IMAP folder, keyed by its raw path. A message has one `mailbox` and any
  number of labels; the label bound to its mailbox is its *location label*.
- In the sidebar an IMAP folder appears as its location label. Filing under it moves mail; a label
  made in plMail on an IMAP account is stored in plMail only and moves nothing
  (`LabelChangePropagator`).

## Step 1: folders as folders (built)

### The sync mirrors, and stops reshaping

`MailboxSyncer` used to re-derive every folder's label from its path on every run
(`segmentsFromImapPath()` → `customChain()` → `bindMailbox()`). Two things followed. A label
renamed in plMail was taken back by the next sync, which found-or-created a label under the old
name and moved the folder to it. And two folders whose paths differ only by the INBOX namespace,
`Work` and `INBOX.Work`, resolved to the same label "Work"; since a folder feeds exactly one label
and a label one folder per account, they took it from each other on alternate syncs.

`LabelResolver::linkFolder()` is now the one rule, used by the sync and by
`ImapFolderProvisioner`:

| Folder | Label |
|---|---|
| Special use (RFC 6154 attribute, or a known name) | Its system label, always |
| New, never bound | A chain read off its path, INBOX dropped and modified UTF-7 decoded |
| New, and that chain already feeds another folder of the account | The chain with its INBOX segment kept ("INBOX › Work") |
| Already bound, path unchanged | Left as it is |
| Moved (renamed by plMail or recognised as renamed by the sync), same parent, label not shared with another account, new name free | The same label, renamed |
| Moved otherwise | A chain read off the new path, as for a new folder |

The one exception to "left as it is": a label whose name is still the raw modified UTF-7 of its
folder ("B&APw-cher"), which syncs before names were decoded left behind. No data migration was
needed; existing bindings are kept.

### Paths, separators and namespaces

- **The separator is the server's.** It comes from LIST and is stored on each `Mailbox` row;
  a child path is its parent's raw path, that parent's separator, and the new name.
- **The top level is the personal namespace (RFC 2342).** New folder asks `NAMESPACE`; on a
  Courier-style server the answer is `INBOX.`, and a top-level folder becomes `INBOX.Name`. A
  server that does not answer falls back to the evidence of the folders the account already has.
- **Modified UTF-7, exactly once (RFC 3501 §5.1.3).** `Mailbox::$fullPath` is stored as it travels,
  already encoded, and goes back to the server byte for byte. Only a name a person typed is encoded,
  in `ImapFolderName`. The folder commands go through the connection, not through webklex's
  `Client::createFolder()`: that one encodes whatever it is given, so an encoded path came out
  encoded twice ("K&APY-ln" → "K&-APY-ln"), follows the CREATE with a full LIST, and with a plain
  EXPUNGE unless told not to.
- **The separator is refused in a name, not escaped.** IMAP has no escape for it: "v1.2" on a `.`
  server is a folder "2" inside a folder "v1". The LIST wildcards `*` and `%`, control characters and
  a top-level "INBOX" are refused as well, all before the server is asked.

### The actions

`ImapFolderManager`, behind the folder list (`AccountFoldersController`). The list opens from
Settings → Accounts → **Folders** and, for a plain IMAP account, from the ⋯ menu on the account's
row in the sidebar (**New folder…**, **Folders…**). The account's folder list in the sidebar ends
with **+ New folder**, and a pencil on its own folder rows opens a rename. An action that works
answers with a turbo-stream — the app's toast, and both copies of the sidebar drawn again, so the
folder is under its account and under LABELS without a reload (`_folder_done.stream.html.twig`);
one that is refused stays in the dialog with the reason. In
the LABELS section a label that is a folder on a plain IMAP account wears a folder instead of a dot
(`SidebarCounts::folderAccountsOf()`), the one visual difference step 1 makes between the two.

- **Create** — `CREATE`, then `SUBSCRIBE`, then the `Mailbox` row and its label as a sync would make
  them. "Already exists" counts as success. Any other refusal is `ImapFolderRefusedException` with
  the server's words, shown in the form; the background callers of `ImapFolderProvisioner`
  (archive, trash, move) still get null and a log line, because a Messenger retry would only ask
  again.
- **Rename** — `RENAME` with raw paths both ways, in the same place. The server renames the folders
  inside along with it (RFC 3501 §6.3.5), so their rows are rewritten at once rather than met by the
  next sync as new folders. The subscription is moved. The server may give the renamed folders a new
  UIDVALIDITY, so a `SyncAccountMessage` follows, and `VanishedMessageReconciler` re-reads a folder
  whose UIDVALIDITY changed.
- **Delete** — the rule `RemoteLabelRemover` already keeps: empty here, empty on the server
  (`STATUS MESSAGES`), and counted here again after the round trip, because `message.mailbox_id`
  cascades. A folder with folders inside it is refused too: what DELETE does to them depends on the
  server (§6.3.4). Its label goes with it only when nothing else is left on it.
- **Never touched**: the inbox and every special-use folder (`ImapFolderManager::isProtected()`).
  Other clients and plMail's own sending, archiving and spam handling find them by name or role.
  Nothing in step 1 moves mail, and no folder is created, renamed or deleted except by one of these
  three actions or by the moves that already provisioned one.

None of the folder actions expunges anything.

## Later steps (not built)

In the order agreed in #221:

1. ~~Mirror the folder tree as folders, with New folder, rename and delete~~ — this page.
2. **IMAP keywords as the label backend**, detected per account from `PERMANENTFLAGS`, with the
   plMail-only label as the fallback and an indicator of which it is. Keywords are atoms, so names
   with spaces, `/` or non-ASCII need an encoding; registry keywords and `$label1`–`$label5` are left
   alone.
3. **Move to separate from Assign label in the interface**: one picker with two sections, folders and
   labels, so assigning a label can no longer move mail and Move to always does. Gmail, which has no
   folders, keeps Move to for its system labels only (Inbox, Spam, Trash, archive).
4. **Outlook categories** as Outlook's label backend, with folders as folders.

After those: a move-to-folder filter action, an `in:folder` search, and an opt-in "convert folders
to labels" wizard, which stays out of #221.

## Things that bite

**The label tree is per user, the folder trees are per account.** Two accounts with a folder
"Clients" share one label "Clients", and a folder inside one of them hangs under that shared label.
Renaming one account's folder therefore cannot rename the shared label, and the folder gets a label
of its own instead. A sidebar that draws each account's folders as their own tree is step 3's.

**A server that does not mark its system folders gets them protected only by name.** Stalwart
answers a plain `LIST` without special-use attributes, so its "Sent Items", "Deleted Items" and
"Drafts" are recognised by name and its "Junk Mail" is not, and is treated as an ordinary folder.

**UIDVALIDITY from STATUS is not always the one SELECT reports.** Stalwart 0.11 gives two different
numbers, so `MailboxSyncer::findRenameSource()` cannot recognise a rename made in another client
there; the folder arrives as new and the old row is removed after its grace period.

**Turbo draws a 4xx into the frame the form is in.** The delete button sits in its row's frame and
targets the modal, so a refused delete answers 200 with the reason, not 422.
