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

## Step 2: labels as IMAP keywords (built)

A label is a tag, and IMAP's tag is the keyword: a flag of the client's own choosing, kept per
message copy like `\Seen`. Off by default per account (`Account::$labelsAsKeywords`, settings key
`labels.imap_keywords`); off, a label on IMAP mail stays in plMail, as before.

- **Capability.** A folder keeps new keywords when its `SELECT` answer carries `\*` in
  `PERMANENTFLAGS` (RFC 9051 §7.1). `ImapPermanentFlags` asks — SELECT, because Dovecot answers a
  read-only EXAMINE with an empty list — and records it on `Mailbox::$keywordsAllowed` (null until
  asked). Asked for every fetched folder when the switch goes on, and again before every write.
- **Names.** `ImapKeyword` maps a label's full name to an atom and back, exactly: printable ASCII an
  atom can hold passes through ("Work", "Invoices/2025", what other clients write), anything else
  is a modified-UTF-7-style `&…-` run ("Follow up" → `Follow&ACA-up`). Keywords starting with `$`,
  and the few older clients write without it (`Junk`, `NonJunk`, `Forwarded`, …), never decode to a
  label; a label spelt like one is encoded so the two cannot collide.
- **Writing.** `LabelChangePropagator::dispatchKeywords()` queues `ApplyImapKeywordsMessage` for a
  label that is a tag on that account — not a system label, not a folder there — with the address of
  every server copy, taken from `MessageCopies::of()`. The handler SELECTs each folder and sends
  `UID STORE ±FLAGS.SILENT` through the connection (webklex's `setFlag()` would prefix a backslash),
  then updates the row's flag mirror. It does not use the echo guard (`Message::$flagsTouchedAt`),
  which is one timestamp shared with star and read: reading is change-based on the mirror, and the
  mirror only moves when the handler has written the keyword, so a pass before the job sees no change.
- **Reading.** At import, a message wears the labels its keywords stand for (`MessageSyncer`). After
  that, both inbound flag doors — the folder listing (`ImapFlagReconciler`) and the line IDLE pushes
  (`ApplyRemoteFlagsMessageHandler`) — call `KeywordLabelFollower`, which reads a **change**: the
  keywords in the row's mirror before the update against after it. Never "the server's set is the
  truth", which would strip every label given while the switch was off. A new keyword makes a plain
  label with no binding (`KeywordLabels`).
- **The indicator.** `LabelPlaces` says per IMAP account whether a label is a folder there, a
  keyword, or plMail-only and why; the label dialog shows it.

### The seam for #415

[#415](https://github.com/karatektus/pl_mail/issues/415) splits `Message` into the email and its
locations (`MessageLocation(message, mailbox, imap_uid)`), so one email can be in several folders.
Keywords live on each copy, so the keyword code never reads `$message->mailbox` / `imapUid`: it
asks `MessageCopies::of()`, which today answers the row's one address and after #415 answers every
location. The read side works per folder listing and is unchanged by it; what #415 has to decide is
what a label means when two copies' keywords disagree (its decision 2).

## Step 4: labels as Outlook categories (built)

A label on a Microsoft account was already an Outlook category before #221: `GraphLabelPolicy` makes
a label a folder only when it came from one (a role or a `graphFolderId`), `ApplyGraphChangesHandler`
PATCHes each message's whole `categories` array derived from its labels and creates master
categories on demand, `GraphCategorySyncer` imports the master list as labels, `GraphMessageBuilder`
maps categories to labels at import, and `ApplyLabelStructureHandler` / `RemoteLabelRemover` rename
and delete the master category. The gap was the way back: the delta query did not select
`categories`, so a category put on or taken off in Outlook after import was never seen.

- **Mirror.** `Message::$graphCategories` (JSON, null until known) is what Exchange last held: set at
  import from the payload, and after every successful PATCH to the array that was sent. It is the
  "before" for reading a change, the way the flag mirror is for keywords.
- **Reading.** `GraphApiClient::DELTA_SELECT` now carries `categories`. A delta link remembers the
  `$select` it began with, so the field only arrives on chains started after that.
  `GraphApiSyncer::followCategories()` runs for an existing message in a delta page when the account
  follows categories: the server's list against the mirror; added names put the label on (an unknown
  name makes a plain label, `GraphFolderResolver::categoryLabel()`), removed names take off labels
  that are categories there, never a folder-backed one. A null mirror only learns. Never "the server's
  list is the truth", which would strip every label plMail gave that Exchange never had.
- **Echo.** The PATCH handler moves the mirror to what it sent, so the delta that reports plMail's
  own write sees no change. No timestamp guard.
- **The switch.** `Account::$followsOutlookCategories` (settings key `labels.outlook_categories`),
  off by default, an icon toggle on a Microsoft account's row (`AccountCategoriesController`, the push
  control's shape). Turning it on empties `graphDeltaLinks`, like migration `Version20261014110000`
  did for `flag`, so every folder's chain restarts with `categories`; existing mail is recognised by
  `graphId` and not fetched again. Writing was never switched and is unchanged.
- **Rename.** Unchanged in intent: the master category gets the new name and every message plMail
  holds with the label is re-pushed (`retagMessages()`), as Outlook's own rename dialog offers. Graph
  documents `outlookCategory.displayName` as fixed after creation, so a 400/405 on the PATCH now falls
  back to `replaceCategory()`: take or create the category under the new name, delete the old one,
  re-tag. Deleting a master category leaves its name on each message as text — the same end state as
  a rename — so the fallback touches no message the rename would not.
- **Delete.** Unchanged: the master category is deleted; the name stays on messages as uncoloured
  text until the next push for that message derives the array without it.
- **The indicator.** `LabelPlaces` now lists Microsoft accounts: `folder`, `category` (not
  followed) or `category_followed`.

What stays for step 3: putting a folder-backed label on a Graph conversation still moves it (that
label IS the folder there); the separate Move to / Assign label picker removes the ambiguity.

### Not verified against Exchange

Everything above is tested with mocked Graph HTTP only. Not known: that a delta page reports
`categories` for a message whose only change was a category (Graph says a delta reports any property
change of the selected set); whether a push notification follows a category-only change; whether
Exchange accepts or refuses a PATCH of `displayName`; how long re-listing a large mailbox takes after
the switch goes on.

### Test guide for the maintainer (Outlook)

On this branch, with migrations run and a Microsoft account connected. "Sync" below means **Sync
now** on the account, or waiting for the next scheduled run.

1. **Off by default.** Settings → Accounts. The Microsoft account's row has a grey tags icon; its
   tooltip starts "Follow Outlook categories — Off". Open any label's dialog (Labels → a label →
   edit): "Where this label is kept" says "An Outlook category on … (changes made in Outlook are not
   followed)".
2. **Writing, unchanged.** In plMail put a new label "Step4 A" on a conversation. In Outlook on the
   web the messages carry the category "Step4 A", and it is in the category list. Sync: the label
   stays on in plMail.
3. **Off ignores Outlook.** In Outlook, add the category "Step4 B" to a message plMail already has,
   and remove "Step4 A" from another. Sync: nothing changes in plMail.
4. **Turn it on.** Click the tags icon: it turns green, tooltip "On". The account syncs every folder
   once more; no conversation appears twice. The label dialog now says "An Outlook category on …".
5. **Follow a new category.** In Outlook add the category "Step4 C" (new to plMail) to a message.
   Sync: the conversation has the label "Step4 C" in plMail, and the label exists in the sidebar.
6. **Follow a removal.** In Outlook remove "Step4 C" from that message. Sync: the label is off the
   conversation in plMail.
7. **No echo.** In plMail put "Step4 A" on another conversation, then sync twice: it stays on, and
   Outlook shows it.
8. **Rename.** In plMail rename "Step4 A" to "Step4 Renamed". In Outlook the category list has
   "Step4 Renamed" and not "Step4 A", and the messages plMail holds carry the new name. Please note
   whether the old category kept its colour (PATCH accepted) or the new one was made fresh in the
   label's colour (fallback), and that `php bin/console messenger:failed:show` is empty.
9. **Delete.** Delete the label "Step4 Renamed" in plMail. It leaves Outlook's category list; the
   messages show the name without a colour. Sync: the label does not come back.
10. **Turn it off.** Click the icon: grey again. Add or remove a category in Outlook; sync: nothing
    changes in plMail.

With **Instant delivery** on, steps 5 and 6 should land without a manual sync; whether they do is
one of the unverified points above.

## Later steps

In the order agreed in #221 (step 3 is not built yet):

1. ~~Mirror the folder tree as folders, with New folder, rename and delete~~ — this page.
2. ~~IMAP keywords as the label backend~~ — above.
3. **Move to separate from Assign label in the interface**: one picker with two sections, folders and
   labels, so assigning a label can no longer move mail and Move to always does. Gmail, which has no
   folders, keeps Move to for its system labels only (Inbox, Spam, Trash, archive).
4. ~~Outlook categories as Outlook's label backend~~ — above.

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

**Keywords are not rewritten when a label is renamed or deleted.** The old keyword stays on the
server; it is only an issue the next time another client changes it, when it reads back as a label
of the old name. Outlook categories do re-tag on a rename (`retagMessages()`); keywords could reuse
that shape later.

**UIDVALIDITY from STATUS is not always the one SELECT reports.** Stalwart 0.11 gives two different
numbers, so `MailboxSyncer::findRenameSource()` cannot recognise a rename made in another client
there; the folder arrives as new and the old row is removed after its grace period.

**Turbo draws a 4xx into the frame the form is in.** The delete button sits in its row's frame and
targets the modal, so a refused delete answers 200 with the reason, not 422.
