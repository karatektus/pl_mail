# Changelog

Notable changes per release. plMail runs its migrations automatically on boot,
so anything that changes the schema irreversibly is called out explicitly.

The published image tags: `latest` follows the most recent release below,
`main` follows the tip of the default branch, and `sha-…` pins one commit.

## v0.0.39 — 2026-08-17

**One migration, additive (a table and two defaulted columns); nothing to do
on upgrade beyond an optional backfill. plMail grows a radar, every logo
colourway becomes a theme worth wearing, and the compose window stops being
taciturn.**

**The radar.** plMail now reads facts out of mail as it arrives — parcels with
their tracking numbers and delivery estimates, flights with their times,
event tickets, GitHub issue and pull-request activity — and turns each into a
card: dated ones in the Happening Soon panel under "On your radar", GitHub
under "From your repos", and a strip above any conversation something was
found in. Extraction is deterministic and local: sender domains, headers and
fixed shapes, no cloud, no model — the radar would rather miss a parcel than
invent one. Five carrier follow-ups are one card (upserted by the thing's own
identity), dismissing a card outlives the carrier's next enthusiasm, and
`app:backfill insights` walks mail that predates the feature. Every source
has its own switch under **Settings → Insights**, and the catalogue is the
extractor registry itself — an extractor added to a build appears there and
starts working with no further wiring.

**Themes with character.** The 32 logo colourways became full themes, each
with a palette of its own — seven properly dark (ink is a printed page's
negative, midnight & gold a night library, aurora a lit night sky), the rest
each on their own paper. Every ink tier meets the contrast floors,
computationally. The theme picker shows the seven classics and folds the
colourways behind **More**; your current pick stays visible until you choose
another. The logo follows the theme by default now, with "choose
independently" bringing the full logo grid back; existing non-default logo
picks were migrated to independent so nobody's choice moved.

**The compose window answers back.** "Sending…" pulses — one dot, two dots,
three — and carries a Cancel for the in-flight moment that presses the cancel
window's own control the instant it renders. A forward opens unsigned, with
the address rows unfolded and the caret blinking in a typable To field; its
quoted original counts as content, so forwarding without a word above it is
no longer questioned as an empty mail. The quote stays folded behind the pill
by default, and **Settings → General → Composing** can open it out instead.

**Also:** every checkbox in settings became a labelled two-segment control;
the favicon URL is versioned so a colourway change reaches the tab without a
hard reload; the icon set is transparent now; and a shared-calendar test that
failed every evening after Berlin crossed midnight ahead of a UTC container
asserts in the page's own timezone.

**The migration:** `mail_insight` (new table), `user.appearance_logo_linked`
(default true, false where a non-default colourway was already chosen). Run
`php bin/console app:backfill insights` once if you want cards for mail that
is already in the database.

## v0.0.38 — 2026-08-17

**One migration, additive with a default; nothing to do on upgrade. The inbox
tabs learn everything Gmail's know, and the logo stops pretending to be
Google's — it is redrawn, recoloured, and handed to the user as a setting.**

**The category tabs carry the whole Gmail hint now.** Each tab wears its
category's icon — outline at rest, filled on the tab you are on — and, while a
category holds conversations you have never been shown, a "3 new" pill in that
category's colour with a second line naming who the new mail is from, newest
arrival first. That pill is deliberately the only number on a tab: the unread
counter that used to sit beside it said something the sidebar badge and the
bold rows already say, and two numbers in one glance was two demands with
nothing to tell them apart. The whole strip is live — pill, sender names and
tab set update in place on the same sync the sidebar badges ride, without a
reload, and a hint retires the moment you look at its tab.

**The pl mark is redrawn and de-Googled.** The topbar monogram was painted in
Google's literal brand hexes; that colourway is gone and cannot be picked
back. The mark itself was tuned on the way — the two letters now share a top
line and a bottom line, the p's bowl is square-ish rather than tall, the l
runs lower before its tail turns so neither letter reads deeper than the
other, and the ink is centred in its frame so icon tiles no longer lean.

**And its colourway is yours to choose.** Thirty-two of them under
**Settings → Appearance → Logo** — single colours, two-tone pairs, ink with
one coloured flick, and gradient sweeps — applied to the topbar as you click
and saved like every other appearance knob. Dark themes get each colourway's
dark-chrome strokes, so an ink-dark mark never vanishes into a dark topbar.
New accounts start on **Berry**, the product's own sweep.

**The favicon follows the person.** The blue envelope is retired; the tab icon
is the mark, served by a route in the viewer's own colourway and revalidated
so a change in settings reaches the tab strip on the next page, not whenever a
cache expired. Anonymous pages get the product default, the static icon set
and PWA tiles are repainted in Berry as the sessionless fallback, and the
manifest and theme-colour follow.

**The migration:** one new column, `user.appearance_logo_style`, defaulted to
`berry` — every existing account simply wakes up wearing the new mark.

## v0.0.37 — 2026-08-17

**No migration and nothing to do on upgrade. A review of the whole codebase,
and what it turned up: one endpoint that leaked which mail exists, a listener
that outlived the window it belonged to, and a good deal of the same check
written out more times than anyone had noticed.**

**The status routes stop answering questions about other people's mail.** Every
action behind `/status/{type}/{id}` — star, archive, trash, label, snooze, read
— resolved through one helper, and that helper failed open three ways. An id
matching no row died with a TypeError, so a *missing* id answered 500 while a
real id belonging to *somebody else* answered 403 — and telling those two apart
is an existence check anybody with an account could have run against every
message and thread id on the server. Both route segments are constrained now,
absence is a 404, and the six actions carry a CSRF token. Nothing in the
browser changed: every caller was already sending that token and the server was
throwing it away.

**One answer to "is this yours".** The check was a private method on the
controller — nineteen of them, in eighteen files, under six different names.
All nineteen were correct; the trouble is that each was a fresh derivation of
one sentence, so the twentieth was a fresh chance to get it wrong, and a
controller that simply never grew one looked no different from outside. There
is a voter now, it fails closed on the seven ownership links that are nullable,
and it reaches a message's owner by the one route the schema guarantees rather
than by a walk that could fatal on a message with no mailbox and no thread.

**And one CSRF check.** Fifteen copies, two names, already drifting — one of
them had its token prefix baked in, so the call site said one thing and the
token was called another. The session cookie's `SameSite` is written down as
well: it was already `lax` and stays `lax`, because the OAuth callbacks are
cross-site navigations that read state from the session and `strict` would
break every account connection. Two authenticated endpoints that checked no
token now do.

**A phone could be left unable to scroll.** The compose window's "no subject?"
warning bound an Escape handler to the document and only ever released it from
its own two buttons, so closing the window with the question unanswered left
the handler behind holding the dead window alive. Alongside it, the one
unguarded lookup in a teardown full of guarded ones — which, had it ever fired,
would have skipped the line that gives the page its scrollbar back.

**Things that were in the wrong place.** The topbar's sync button was a
controller called Dev serving `/dev/sync`, a URL an operator might reasonably
block, with inline SQL inside it. The sidebar counters lived in `src/Twig`
despite holding no Twig and being injected into a controller. The compose
controller gave up its uploads, its request context — an array whose shape was
written out in eight docblocks — and the decisions that were never about HTTP.

**Also:** four debug `console.log`s, one printing label activity on every use;
an autocomplete controller whose body had been commented out and which no page
had mounted in some time; a JavaScript dependency imported by nothing; and an
end-to-end test that only passed if you happened to have an environment
variable set.

## v0.0.36 — 2026-08-13

**One migration, additive and nullable; no reset. Mostly Appearance, which
grew a good deal and then had to be rebuilt twice because the first two
answers were the wrong shape. Also two bugs that shipped in v0.0.35, one of
them ours from that same release.**

**Appearance is two cards with a split between them.** The controls on one
side, a live preview on the other, resizable between 240 and 900 pixels and
remembered per user. Both cards are the same height and the controls scroll
their own body, the way the mail panes do, so the page does not grow a second
scrollbar. On a phone the preview is not dropped: a button in the settings
card header reveals it, pinned to the top with the controls still usable
underneath. The handle between them is the calendar's, down to the grip and
the strain it shows when a drag pushes past the limit.

**The mail list can be told what to show.** The **account corner** — the
coloured triangle in a row's lower-left saying which account a message arrived
on — can be switched off; it stays on by default, since wanting it gone is not
the same as nobody wanting it. Preview lines and unread emphasis are settings
now too. Sender discs are not, and cannot be: that disc *is* the row's
checkbox, so switching it off would take selection with it. It paints over the
identity instead and keeps the target.

**Type and density.** A font family and a text scale across the interface —
not the compose editor, whose size is per-message formatting that ends up in
the mail you send. Density can be set once or given separately to the sidebar,
the list and the reading pane. Only density is per-surface: the list and the
reading pane are one painted surface, so opacity, blur and radius cannot
differ between them, and text size resolves against the document root.

**Backgrounds apply when you pick them.** Choosing a preset, choosing a solid
colour, or going back to the theme wrote the setting and changed nothing until
the page was reloaded — only an uploaded image ever took effect immediately.
A solid colour also had a setting, an enum case and a resolver arm, and no
control anywhere on the page.

**The sidebar folds.** Labels and accounts collapse, remembered per user
rather than per browser, and rendered folded by the server so nothing snaps
shut after the page paints. A collapsed label section says how many unread
threads are inside — counted as threads, not summed across labels, because a
thread under two labels would otherwise be promised twice.

**Sidebar density now does something.** It had a setting, a token and one
consumer: the Compose button. Every other row was hardcoded. Rows take their
padding from a tier now, and Comfortable is identical to what it was, so
nobody's sidebar moves — one stray twelve-pixel growth on the Compose button
is undone. Compact stays finger-sized on a touch screen, which Comfortable
was not quite either.

**Account health only appears when something is wrong.** It used to sit in the
settings nav permanently to say that everything was fine. It is a page about
exceptions, so it now shows up when there is an exception — and while you are
standing on it, so the page you are looking at still has an entry.

**A reset hands its registrations back.** Truncating local data left Google and
Microsoft delivering notifications for calendars and mailboxes that no longer
existed, for up to a week, with nothing able to match them. Reset and calendar
removal now revoke the provider-side watch first, best-effort: a failed revoke
is logged and stepped over, because a reset that refuses to run because a
provider is unreachable is worse than a stale channel.

**A dead mail push is now found by evidence rather than by waiting.** The old
rule was thirty-six hours of silence, which cannot tell a broken push from a
quiet mailbox at any threshold. plMail now records when the mailbox actually
changed: mail that arrived and was noticed by a poll, with no push announcing
it, is one push demonstrably missed. Health also separates a watch that
**lapsed** — renewal never ran, look at the scheduler — from one that is alive
and silent, which is the Cloud Pub/Sub leg, and shows the three dates it judged
from. **Note:** the second verdict needs the new column, so existing accounts
are judged on expiry alone until their mailbox next changes.

**Settings and admin share one card.** Twenty-two settings sections had three
different shapes between them; admin had a card macro and six hand-rolled
copies beside it. The chrome is defined once now. Nothing about admin's
collapsing panels or their summaries changed.

**Two bugs from v0.0.35, and the second is ours.** Changing any density — or
any tile-shaped control — **blanked the whole settings pane**. `sr-only` is
absolutely positioned and a label is not, so those radios were positioned
against the pane rather than the box they appear in; clicking one focused it,
the browser scrolled it into view through every scroll container above it, and
a pane that clips its overflow scrolled its own contents out of sight. One rule
fixes every such control in the app. And a **bulk action re-rendered the list
under the next click** — the list toolbar lives inside the frame that was being
replaced, so a second bulk action could land on a button that was gone, or on
one whose selection had just been cleared, and do nothing quietly. The refresh
now swaps rows, tabs and pagination without touching the controls you are
using, and leaves your selection alone.

## v0.0.35 — 2026-08-13

**Two migrations, both additive and defaulted; no reset. Contains a security
fix that matters on any instance with more than one user — if you run one,
update. Most of the rest answers a manual test pass of the running app, and
two reported defects were investigated and deliberately left alone because
they turned out not to be defects.**

**Contact autocomplete was not scoped to its user.** `ContactAutocompleteField`
named its entity class and never said which rows belonged to whom, and
`Contact` is per-user — so on a multi-user instance the suggestion endpoint
listed *other people's* correspondents by name and address, no id-guessing
required, and a contact id from another account resolved and could be
addressed on a draft. One user-scoped query builder closes both, and it fails
closed: no signed-in user now means no contacts rather than all of them.
`LabelType` had always done this; this field was the outlier. Single-user
instances were never exposed.

**Undo send could confirm a cancellation over a mail that had already gone
out.** The handler read the cancelled flag and then sent, and a cancel arriving
between those two steps committed too late to matter — HTTP 200, "send
cancelled", message delivered, with the row asserting both at once. Reproduced
three times out of three against a real SMTP sink at 9.9 seconds of the ten
second hold. The cancel and the worker now race in one atomic statement each,
so the database decides who won and **both sides learn the answer**: a cancel
that loses says the message had already been sent, instead of handing back an
editable copy of delivered mail. The window the interface offers is eight
seconds against a ten second hold, so losing is rare rather than routine; a
claim expires after fifteen minutes so a killed worker cannot wedge a draft.

**Scheduling a send no longer forks a second draft.** Autosave rewrites the
draft and send URLs from its own response as a new message acquires an id, and
the schedule action was never in that list — so the pill posted to the
id-less route and built a second message, leaving a duplicate in Drafts
forever.

**The recipient field stops committing addresses nobody chose.** The suggestion
panel is absolutely positioned and overhung the Subject row, so a click aimed
at Subject landed on the panel instead: it committed whatever was highlighted
as a recipient and left the caret in the address box, so the subject you then
typed went into the recipient field. The panel now takes real room while it is
open.

**The sidebar keeps up, and folds.** Badges refreshed only when a sync arrived,
so a bulk action changed the rows and left the counts behind until a reload.
The label and account sections now collapse, remembered per user and rendered
folded server-side rather than snapped shut after paint. A collapsed label
section summarises its unread as a count of threads, not a sum of per-label
numbers that would count one thread twice.

**Sent no longer carries an unread badge.** Thread labels are the union of
their messages', so replying gave the thread a Sent label while the unread mail
was the incoming one — and Sent then advertised somebody else's unread count.

**Appearance grew a good deal.** A mail-list group with the account corner as a
toggle (still on by default), preview lines and unread emphasis; a font family
and a text scale; a live preview that shares the real row's style hooks rather
than imitating them; and density that can differ between the sidebar, the list
and the reading pane. Two limits are worth stating plainly rather than
pretending otherwise: sender avatars cannot be switched off because that disc
*is* the row's checkbox, so "off" paints over the identity and keeps the target;
and only density is per-surface, because the list and the reading pane are one
painted surface and text size resolves against the document root.

**Also:** a compose window that fails to open now says so instead of leaving
Turbo's bare "Content missing"; the list preview stopped running a tag-stripper
over text that was never markup, which was deleting what people had actually
written; labels can be deleted from the sidebar dialog as well as from
settings, and the confirmation now admits the label goes from every account;
and the message list revalidates after a Back rather than showing a cached
snapshot.

**Two reported defects were left alone on purpose.** An empty label name does
refuse to submit, focus the field and fire its invalid event — the validation
works, and it was not going to be replaced on a guess. And the same
conversation appearing twice across two accounts is two threads in two
mailboxes, which is what the data model says; merging them means cross-account
thread identity, and that is a decision rather than a patch.

## v0.0.34 — 2026-08-13

**Four migrations, all additive and all backfilled — no reset is needed, and no
existing install should look different on the morning after except where this
entry says so. The compose window had six buttons that did nothing; it now has
none. Most of the rest answers a second external audit of the running app.**

**Every button in the compose window does something.** Six of them were
placeholders — named, so a screen reader could reach them, and inert. The
emoji button now opens the whole Unicode set with search, skin tones and
recents, served by the app rather than a CDN, and it deliberately does not
interpret what you type: `:)` and `:smile:` stay as you wrote them. The image
button, paste and drag-and-drop all insert an inline image that travels as a
`cid:` part rather than an attachment. The link button opens a small panel with
the address and the text, prefilled from whatever you had selected, and a link
already in the message offers Open, Change and Remove. **Signatures** are a
settings section of their own, account-wide with per-address overrides, and an
address can inherit, sign with nothing, or carry its own — three states that
stay distinct. The **⋮** menu holds priority, a read-receipt request and a
plain-text mode that warns before it discards formatting. **Encrypt is still
disabled**, with a tooltip saying so; it needs certificate storage and key
custody, and a button that pretends otherwise would be worse than an honest
one.

**Mail can be sent later.** The chevron beside Send offers a few presets and a
custom date and time, resolved in the timezone and clock format you configured
rather than the browser's guess. A scheduled draft says so in the list and can
be called off from the row. The hold has a floor of a minute and the ceiling
the JMAP session already advertised, so the web composer and the API cannot
disagree about how long mail can wait.

**Read receipts work in both directions, and the default is to send nothing.**
Requesting one is a tick in the ⋮ menu. Being asked for one is the half that
matters: the default is **never send**, set per address, with "ask each time"
and "always" as opt-ins — and an automatic send is downgraded to asking
whenever the address the receipt is requested *to* disagrees with the sender,
because that mismatch is a known trick. A receipt that comes back marks the
sent message read instead of landing in the inbox as a cryptic bare message.

**New mail is marked as new until you have seen it in a list.** A different
axis from unread on purpose: a thread you scrolled past but never opened is no
longer new, yet is still unread. It shows as a badge on the row and a dot on
the inbox tabs and sidebar, and it expires 24 hours after the last message, so
it is something you notice rather than a debt to be worked off. Existing
threads are backfilled as already seen — nobody's mailbox lights up on deploy.

**When an account breaks, the app says so and offers to fix it.** A dead
sign-in was recorded faithfully in a column no template ever read, so a single
refused Google grant could produce thousands of log lines and stop three
calendars syncing while the interface said nothing. There is now a health
section that names the problem in a sentence and offers repairs that keep the
mailbox — chiefly reconnecting an account **in place**, which writes a fresh
grant onto the existing row and keeps mail, labels, rules, threads and
calendars, and which refuses outright if the re-consented identity is a
different address or provider. A calendar failing permanently now backs off
instead of re-reporting the same failure every cycle, and still reports the
first occurrence and any change.

**Reordering your accounts no longer changes which one sends.** `sort_order`
was quietly doing three jobs — row position, the primary account, and the
identity colour beside each account — so dragging a row to tidy the list
changed your outgoing address and repainted two accounts' marks everywhere.
Display order is now only display order, the primary account is chosen with
its own button, and the colour is stored per account and backfilled from the
old order, so no existing install sees its dots move. That coloured dot was
never a status light; account status lives on the health page.

**A page of mail is drawn in about twenty queries instead of a hundred and
twenty.** Search was the worst at a hundred and sixty-seven. Half the cost was
not the obvious N+1 at all: a Twig global re-ran its query every time anything
touched it, and the row template touches it once per row. Search had never
preloaded labels. A query-count ceiling now guards every list, because a
reintroduced lazy load costs exactly one page of rows and cannot hide under it.

**The calendar is drawn in your timezone.** The current-time line and the
new-event defaults were computed in UTC — three places all falling back to the
process default, which the container pins to UTC. An event saved to a *hidden*
calendar was also invisible with no error, because the editor lists hidden
calendars and the views do not; the save now lands somewhere you can see, and
the editor marks the hidden ones.

**Fixes found by testing the running app, in the places nobody looks:**
confirmation messages were written to the session by five controllers and read
by nothing, so those sentences never appeared at all. Scheduling a send under a
minute away was refused by the server and the refusal was rendered nowhere, so
the composer simply sat there. Clicking the empty space below a signature put
the caret at the end of the sign-off, so the first sentence typed was swallowed
into it and the message then counted as empty. The theme preset tiles each
carried `data-theme`, which is the selector every theme block uses, so every
tile redeclared the whole palette onto itself and its label was painted in the
theme it named — at 1.22:1 against its background. Compose toolbar labels were
Swiss German (`vergrössern`) in an otherwise `ß` interface; the inbox tab
"Updates" and the system folder names in settings were never translated at all.

## v0.0.33 — 2026-08-12

**No migration. Two features, a fix, and a change to how the image is published.**

**A message shows what you wrote, not what you quoted.** Open a thread and every
message repeated the whole conversation beneath its own reply — the same quoted
history stacked over and over, so the actual new sentence was a scroll down from
the top of each message. Each message now shows only its own text, with the
quoted reply-history folded behind a **Show quoted text** toggle like the
composer's. The fold happens as the message renders, over the already-sanitised
body inside its sandboxed frame, and it is deliberately cautious: it collapses
only when there is genuinely new text above the quote, so it can never hide a
word you wrote. Plain-text mail is left as it is.

**Saving an attachment asks where it should go.** Saving a file to a connected
service used to drop it into one preconfigured folder, and every service was
offered for every attachment — including a photo library for a PDF it could
never accept. Now the menu shows a photo library (Immich, Google Photos) only
for images and videos, and file storage for anything; and picking one opens a
destination picker — a folder tree for Nextcloud and the other file stores, an
album list for the photo libraries, each able to create a new folder or album on
the spot. The chosen destination is validated on the server against path
traversal and albums that are not yours, and a saved-file *rule* keeps its own
configured folder, so a one-off save can never redirect it.

**A sent message in a thread carries its date.** A reply this account sent has a
send time but no arrival time, and the thread showed only arrival — so a sent
message was the one message in a conversation with a blank where every other
message had a date. It now shows its send time there, as it already does when
opened on its own.

**The image is built once, on a release.** It used to build on every push to
`main` and again on the release tag seconds later, and a third time on every
pull request — which already builds the same image to test it — so publishing
one release paid for the build several times over. It now builds only on a `v*`
tag. `latest` and the version tags still publish on release; `main` still
follows the tip of the default branch for anyone who wants it.

## v0.0.32 — 2026-08-12

**No migration. Two fixes from a live instance.**

**A conversation reads in the order it happened.** A reply this account sent
has a send time but no arrival time, and a thread ordered its messages on
arrival alone — so every reply sank to the bottom of its own thread, below
mail that came in after it, and the reply box quoted the wrong "latest"
message. The thread now orders by when each message actually happened —
received by arrival, sent by send, a draft by when it was written — computed
in the query rather than stored in a column beside the three dates that
already hold it.

**The message body no longer collapses on a hard load.** Opened by clicking, a
long message rendered at its full height; opened by F5 or a deep link, its
frame shrank to a sliver with the whole message scrolling inside it. The
sandboxed frame measures and reports its own height the instant it is parsed,
but the app's script is deferred, so on a full load the report arrived before
anything was listening and was lost — and the frame never repeated itself. The
frame is now asked for its height once the page is ready to hear it.

## v0.0.31 — 2026-08-12

**One migration, and it is not reversible-sensitive — it installs `pg_trgm`
and adds trigram indexes so search can match inside a word; the trigram index
on the message body approaches the size of the body column, and every sync now
maintains it, which is the price of substring search. No reset is needed. Most
of this release answers a 40-item external audit of the running app, and the
single largest change moves every rendered email into a sandboxed iframe.**
An optional `bin/console app:backfill recipients` repairs one class of old row
(below); everything else takes effect as-is.

**Mail is read on our terms now.** The HTML body renders inside a sandboxed,
opaque-origin iframe with its own restrictive CSP, so a future sanitiser gap is
no longer XSS in the app's own origin with your session cookie, and email CSS
can no longer collide with the app's. Remote images are blocked until you ask
for them, and when you do they load through a signed, SSRF-hardened proxy —
the sender never sees your IP, and a tracking pixel never fires on open. A
message sitting in Spam wears a warning, and a sender whose display name
impersonates a domain it does not own is called out. (Two bugs met on the way:
the proxy sat behind the login wall the sandbox can't send a cookie through, so
no proxied image ever loaded; and the fetcher passed one option the HTTP client
refuses, so it threw on every fetch. Both fixed, with a test that finally checks
an image renders *inside* the frame.)

**Compose can't send by accident.** Enter in the recipient field makes a chip;
it no longer submits the message — a stray Enter used to send a half-written
mail to whatever text was in the box. Addresses are validated at the chip and
at the server, an empty subject or body prompts first, and Undo reopens the
compose window where the send came from with everything you typed intact,
instead of filing a silent draft. Compose also defaults to your primary account
and is fully German.

**The ghosts are gone and the tab stopped hammering the server.** Seven
contentless "Jan 1 1970" messages in Spam were unparseable IMAP fetches the
syncer persisted anyway; it now refuses them and reaps the ones already stored.
Idle polling dropped from four requests a second to at most two every ten, and
none at all while the tab is hidden. Counters agree with their lists, a unified
list marks each row with the account it came from, and stepping back out of a
thread no longer flashes an empty list.

**Search finds part of a word.** `wirhub` matches `help.wirhub.de`, and
`Testmai` matches `Testmail` — prefix and substring, on top of the full-text
match that was there. The search box clears when you change folder, a page past
the end clamps to the last one, and a bad `?tab=` or a non-numeric id answers
404 rather than 500, on a branded error page.

**German mail reads as German.** Dates, weekday and month names now render in
the reader's locale, `<html lang>` is set, mobile zoom is no longer blocked, and
button/link accessible names are filled in. Contrast is fixed structurally:
button and badge ink is derived from the accent's own luminance, so a light
accent gets dark text instead of unreadable white.

**The calendar dialog says when it refuses.** Saving an event whose end is
before its start showed nothing — the server's 422 had no visible outcome; it
now renders the error inline and announced. The 00:00 row is labelled, the
event dialog traps focus, the agenda shows time ranges, the start time defaults
to the next full hour, and the checkbox help text no longer contradicts itself.

**Smaller things.** The sidebar's "More" section holds Snoozed, Archive, Spam
and Trash, its chevron turns, and its rows step in. The reading pane's sender
avatar wears the same colour the list gives that sender. A message's account is
a small corner mark on its row rather than a dot that read as unread. And the
message frame stopped popping a "Message content" tooltip on every hover.

**No migration for this next part. One optional command repairs old rows:
`bin/console app:backfill recipients`.** Every message ever synced over IMAP
stored an empty recipient
list. webklex hands To/Cc/Bcc over as an `Attribute`, which implements
`ArrayAccess` and nothing else, so the `foreach` that read it walked the
object's (non-existent) public properties, ran zero times and returned nothing
— no error, no warning, and no recipients. The message header therefore had no
"to" line, reply-all had nobody to reply to, and no contact was ever harvested
from a recipient. Gmail and Graph were unaffected; they parse the header and
the `toRecipients` array directly.

Nothing was lost with it: the headers themselves reach a different column, so
the backfill re-derives the recipients from the row itself — no mail server, no
resync, and no reset. It only fills lists that are empty, so a correct capture
is never overwritten by a poorer re-parse. Until it is run, the message header
reads the same headers on the fly, so a mailbox looks right either way.

**The message header names the recipients at a glance, and a message with none
says so.** The header row now reads "to Alice, Bob +2" instead of "Details",
with the full list still in the expander. A message that genuinely names nobody
— a bcc delivery, a list that hides its members — states "undisclosed
recipients" rather than dropping the line, because an absent line reads as a
bug and a stated absence reads as a fact. The expander gained a copy button per
address and one for the whole header block. And reply-to, mailed-by and
signed-by now appear on IMAP mail: webklex spells stored header names with
underscores, and the panel looked only for the hyphen form.

**Attachments download again, and "Save to…" is a menu you can see.** Two dead
clicks in the message view, neither of which logged anything. The download link
was intercepted by Turbo, which fetched the file itself and discarded it
because it was not HTML — the server answered 200 and the browser saved
nothing. The save-to menu was clipped out of existence by the `overflow-hidden`
that rounds the chip's corners: it opened below the box it was positioned
against, so it was painted nowhere and could not be pressed.

## v0.0.30 — 2026-08-12

**One migration adds the columns this release thinks with; nothing is
irreversible. Mail read anywhere else is read here too — on every provider.**
plMail captured an IMAP message's flags once, at ingest, and never looked
again: read on the phone stayed unread here forever, a star set in another
client never arrived. The deletion sweep's folder listing now carries flags
in the same single command, so presence and read-state are learned in one
pass and cannot disagree with each other. Locally, seen and starred apply
through the same paths the buttons use — thread counts recounted, JMAP
clients notified — and a change you just made here is never reverted by a
server answer that predates it: an unconfirmed local change parks its row
until the provider acknowledges, which is what stops the flap loop instead of
merely making it rare.

**The idle connection now reports everything it hears.** It used to react to
new mail and discard the rest of what the server told it. A deletion
announced mid-idle sweeps its folder within seconds instead of within two
fifteen-minute cycles, and a flag change announced mid-idle refreshes the
same way. The idle connection stays a trigger, never an authority — every
answer still runs through the sweep's own safety rails.

**Gmail and Graph read-state was broken too, each in its own way.** A Graph
delta carries isRead and the star and was read only for the folder; both
apply now, and an absent key preserves rather than resets. Gmail's batch
handler dropped every already-known id before fetching anything — which had
quietly stopped the label mirroring for relabelled messages as well; with the
filter corrected, label changes and read-state flow as v0.0.29 intended.

## v0.0.29 — 2026-08-12

**Two migrations run on boot: one adds the column the deletion sweep marks
suspicions in, one deletes the retired "labels stay local" setting from the
settings bag — the second is irreversible and the whole feature it configured
is gone. And after updating, a reset is the recommended path** (`app:reset`,
which now clears calendar data too): mail archived in Gmail before this ships,
label trees never pushed under the old toggle, and stragglers from
UIDVALIDITY rebuilds all predate the new mirroring and heal by re-syncing
fresh rather than by machinery.

**The lifecycle mirrors, on every provider, in both directions.** A message
deleted on the server is deleted here — which none of the three providers
ever did. IMAP learns it from a periodic full listing per folder, and the
sweep is built to be wrong safely: absence only marks; erasure additionally
requires every folder listed since the mark and a final probe answering an
explicit "not there"; a folder that answers empty after holding thousands is
treated as a rebuild, not a purge, and suspends reaping account-wide; a
changed UIDVALIDITY strips addresses and re-matches by Message-ID, never
wipes. Gmail's feed finally subscribes to deletions; Graph's delta removals
distinguish a move from a disappearance. All three delete through one shared
path — replacing two hand-rolled ones that each leaked different files.

**Labels mirror without asking.** The "labels stay local" toggle is gone:
labels created here are created there (Gmail labels by API, Graph folders by
API, and IMAP folders by CREATE — with the namespace prefix and separator
read from the folders the server already has, not from a convention). Labels
changed there change here, removals included, scoped so one account's feed
can never touch a sibling account's same-named label. Archiving means the
same thing everywhere: Gmail's inbox-departure becomes the Archive label
here and vice versa, Outlook's archive folder resolves by its well-known
name before anything creates a duplicate beside it. Discarding a draft
finally discards it on the server too.

**The calendar pane and mail navigation stop fighting.** A mail-bound click
in split view swaps only the list — the calendar holds perfectly still. A
fullscreen calendar demotes to split (or to mail where the row cannot hold
both) on any mail-bound click, wherever it came from: sidebar, drawer,
another page. "Fullscreen" is judged by what covers the mail, not by what
the mode is called — one toggle press on a narrow window counts. A reload
paints the remembered layout server-side with the pane's body embedded in
the page, so mail and calendar arrive together instead of the calendar
filling in a beat late.

**Smaller honesties.** The reading pane's sender avatar wears the same
colour the thread list derives for that sender, instead of one blue gradient
for everybody. Inbox category tabs exist only while they hold mail — Gmail
itself has quietly retired Forums — and a lone Primary tab hides the whole
row. The thread list's refresh button is gone; the topbar's sync button is
the one that answers. `app:reset` takes calendar data at the same depth it
takes mail, keeps event suppressions at every depth, and says so honestly in
its prompts.

## v0.0.28 — 2026-08-10

**No migration. German mail still rendered as "Ã¼ber", and the sync was not to
blame this time.** "Stop believing a charset label the bytes disprove" fixed
the MIME layer, and it holds: a mislabelled part decodes correctly even inside
a multipart invite. The damage was one layer lower down. A stored body is
UTF-8 whatever it arrived as, but the sender's own `<meta charset=iso-8859-1>`
came through that conversion unchanged — and the parser behind the HTML
sanitizer follows the specification's encoding sniffing algorithm, which
prefers that tag to any default. So the rendered copy was decoded as latin-1
all over again. The declaration is now corrected to match the bytes before
anything parses the document, which is what the HTML specification asks of
anything that transcodes one.

**Mail already showing mojibake can be repaired in place**, unlike last time:
only the rendered copy was ever wrong, so `app:backfill safe-html-charset`
re-derives it from the body as stored. No resync, no mail server, and the
sender's original copy is left exactly as it is. Run it once after updating.

**A calendar invite written in latin-1 no longer costs its event.** Invites
are stored as they arrive and RFC 5545 says UTF-8, so nothing converted them —
and one 0xE4 in a SUMMARY was not a mangled title but an INSERT Postgres
refused, taking the extraction with it.

**Light and Dark read mail on their own light.** v0.0.26 made the reading
sheet a theme decision and then left Paper's cream in the three blocks without
a named selector — which is why the light theme's reading pane looked like
Solar had bled through. Light now reads on its own white and zinc; both dark
palettes on their palest slate.

## v0.0.27 — 2026-08-10

**No migration, and nothing in the app itself — this release moves the test
suite onto the server the app actually ships on.** CI used to serve the E2E
suite from PHP's built-in dev server, and everything that server could not do
had grown an accommodation: a router script written for the occasion, a
php.ini copied in with sudo, and finally a restart loop because it segfaulted
mid-suite. All of it is gone. The workflow now builds the repository's own
FrankenPHP image and runs PHPStan, PHPUnit and the browser suite against the
compose stack — the same commands, the same server, the same stack a
developer runs locally, so a green suite finally says something about plMail
as shipped.

Getting there flushed out three real defects: two specs talked to a
hard-coded compose project and could seed a database the browser was not
looking at (one of them had been silently not stopping the Mercure hub it
believed it stopped); and a fresh checkout could not boot the test stack at
all, because the init path had lost the entrypoint's dependency bootstrap.
A test that flaked when a fast runner sent two replies inside the same second
now makes its own second boundary.

## v0.0.26 — 2026-08-10

**No migration. Every theme now defines every variable — all 38 of them,
explicitly, in every palette.** Three themes were four-line diffs against a
light-tuned base, which is how Nord got the light theme's link blue on a
near-black surface and an ink ramp that ran backwards. The real remnant bug
was worse than CSS: picking Paper seeded its clay accent onto the account
itself, where it survived onto every theme chosen afterwards — and picking
System on a dark desktop painted the app light until the next navigation.
Both fixed. A parser-backed test now fails the build if a theme ever misses a
variable, and a browser test switches through every theme without reloading
and asserts the result is pixel-for-pixel what a fresh load produces.

**The reading pane wears the theme.** Two conflicting definitions of the mail
sheet had been shipped, and the older plain-white one was silently winning —
the warm-paper redesign was dead code. Resolved in the redesign's favour, and
the sheet's seven colour channels now come from the theme: Nord reads mail on
snow storm, Solarized on its own base, Paper on cream. This is why the thread
list looked like the odd one out on the light theme — now the whole reading
surface agrees with wherever it is.

**Search respects the bin.** A deleted conversation no longer turns up in
search results through its other labels — the same rule the list views
learned in v0.0.25, applied to search's own SQL. `in:trash` and `in:bin`
still find it on purpose.

**The default calendar speaks your language.** A user provisioned in German
gets "Persönlich", not "Personal". Calendars that already exist keep their
names — they are the user's data, and may have been renamed.

The E2E dev server on CI restarts itself if it crashes, so one segfault can
no longer disguise itself as forty-five failing tests.

## v0.0.25 — 2026-08-10

**No migration. A message that moves is still one message.** Moving mail —
trash, archive, snooze, a filter filing something away — used to leave the row
holding its *old* IMAP address, so the destination folder's next sync met the
real message as a stranger and inserted it again: one ghost per move, which is
how a mailbox of 35 became 86. Moves now mark the row as relocated and sync
reconciles it by Message-ID; an external move is told apart from a genuine
copy by asking the server, and an answer of "could not tell" never merges and
never deletes. Installs already carrying ghosts heal on their own syncs — each
duplicate group collapses onto the copies the server actually confirms, and a
message whose every copy is unconfirmed is left strictly alone. The
flags-worker no longer warns forever about vanished UIDs, and a
half-completed move on a server without native MOVE support can no longer be
retried into a second copy.

**Archive works now.** Archiving removed the Inbox label and added nothing, so
the Archive view — which lists by the Archive label — was permanently empty.
Archived mail is now filed under Archive, keeps its other labels, and the
sidebar's "More" section stays open while you are inside it instead of
snapping shut on every navigation. Spam also actually exists: the sidebar
visibility toggle had nothing to show, because there was no Spam view at all —
there is now, in every locale.

**Trash stays in Trash.** A trashed thread kept its other labels and went on
listing under them; every list and unread badge now excludes trashed mail
except the Trash views themselves. Search is deliberately unchanged for now —
it has its own query builder and an `in:trash` operator that deserve their own
change.

**The little lies of the UI.** The onboarding integrations row wraps instead
of forcing the modal to scroll sideways, and provider names no longer break
mid-word. Dropdown search results mark exactly what was typed, in bold,
without the phantom trailing space (a flex gap painted beside the highlight) —
and the search box inside long dropdowns, which the v0.0.22 theming had
silently removed from every select in the app, is back. Selecting text in a
modal and releasing the mouse outside no longer dismisses the modal — a
dismiss now requires the whole gesture, press and release, on the backdrop.
Switching density no longer rewrites your background setting as a side effect
(that was the "page breaks until reload"). Two-factor copy now protects your
"account" rather than your "mailbox", in every locale.

**The calendar E2E specs drive dropdowns the way a person does.** Ten specs
still fired the retired native-select gesture at the themed combobox and died;
they now go through a shared helper that operates the widget by its ARIA
contract — which doubles as a regression tripwire on the accessibility the
theming promised. A new spec pins that contract directly.

## v0.0.24 — 2026-08-10

**No migration, and no runtime change at all — this release exists to turn the
release checks back on.** The E2E workflow had been red on every tag since
v0.0.19: a backup-exporter test hardcoded that `APP_ENCRYPTION_KEY` must appear
in an exported config, which is true wherever the key is set in the real
process environment (compose does this) and false on the CI runner, where the
key exists only in `.env.test` — a layer a backup deliberately does not read,
because the shipped `.env` defaults are placeholders and restoring
`mailto:admin@example.com` over a working install is the exact harm the
exporter is scoped against. The exporter was right; the test now derives its
expectation from the exporter's own rule and pins both directions, so it can
never again be green on one harness and red on the other. If this tag's E2E
run is green, that is the fix proving itself.

## v0.0.23 — 2026-08-10

**No migration. A reply you send is one message again.** A message sent from
the web frontend on an IMAP account used to come back from the Sent folder as
a stranger: the composed row carried no Message-ID, and the MIME was left for
Symfony to stamp — which mints a fresh id on every serialisation, so the copy
that went to the recipient and the copy appended to Sent did not even agree
with each other. The next sync inserted the Sent copy as a second message in
the same conversation. Sends now mint one Message-ID before anything reads the
mail, written to the row and the headers alike, and the Sent-folder sync
reconciles on it — a provider's own auto-saved copy is recognised and dropped
too. The rows the old path left behind repair themselves: the next sync of the
Sent folder pairs each ghost with its imported twin and removes it, so
affected conversations heal without anyone touching them.

**Answering a conversation moves it back to the top.** Thread lists sorted on
the last message the thread *received*, so your own reply left the
conversation buried under everything that had arrived since. Sending now
records the activity the same way an arrival does — forward only, so a
backdated arrival cannot drag a live conversation down, and draft autosaves
never reorder anything. The Sent list sorts by your own send dates as a
consequence rather than by luck.

Two counters that were only ever right by accident of the duplicate are right
on their own now: a reply draft counts toward its conversation's message
count, and a sent reply updates the conversation's labels immediately instead
of waiting for the duplicate to drag the Sent label in.

## v0.0.22 — 2026-08-09

**One migration runs, and it is irreversible — but it changes no table.** It
deletes the per-account "sync only the last X mails" setting from the settings
bag, because the feature is gone: the cap applied per sync run, not per
mailbox, so an account that outgrew it between runs lost the *middle* of its
mail — interior holes, not a window. Removed everywhere: the settings control,
the caps in both the IMAP and Gmail syncers, and the capability the app read.
An account that wants its full history re-syncs (`app:reset`) or is re-added;
previously capped Gmail accounts simply complete their backfill on the next
sync.

**Dropdowns finally wear the theme.** Every user-facing select is a themed
widget now — the same one compose's recipient fields always had — because a
native select's popup obeys the operating system, not the stylesheet, and
looked pasted-on in every dark theme. The native selects remain underneath as
the source of truth: forms submit identically, and without JavaScript
everything degrades to working controls. Includes the five selects the filter
rule builder creates in JavaScript, which no earlier styling pass could reach.

**Finishing a login twice is not forbidden.** Submitting the two-factor code
twice — a double click, a second Enter, the browser's "resend?" after Back —
answered the second attempt with a bare 403 at the exact moment the login had
succeeded. The resubmit now lands where the first submit went, and the form
guards itself against double clicks.

**The backup sheds three more machine-local values.** `MERCURE_JWT_SECRET`
pairs the app with the hub container *beside it* — restoring another
machine's copy re-keys half of a running pair and kills every live update
until the whole stack restarts. `APP_SECRET` signs cookies and nothing
durable — the only thing another machine's copy can do is keep another
machine's cookies alive on the new install. `TRUSTED_PROXIES` describes the
machine's own reverse-proxy chain. Old backups carrying any of them import
fine; the values are classified and left alone.

## v0.0.21 — 2026-08-09

**No schema changes.** The backup grew a format version, not a table.

**A config backup knows its own operator now.** Backups carry every user and
everything they configured: password and app-password hashes, two-factor
secrets and recovery codes, mail accounts with their IMAP/SMTP credentials
and OAuth tokens, aliases, integrations, filters, labels, calendars, and
share and booking links — whose published URLs keep working after the move.
Everything sensitive travels only inside the encrypted envelope, re-encrypted
under the target install's key on arrival. Restoring onto a fresh install
ends at sign-in instead of "create the administrator"; importing onto a live
install never overwrites an existing user — found means kept, and the review
says so. Older plMail versions refuse the new format whole rather than
applying half of it. Mail, calendar entries, sync state and per-browser
grants stay out, deliberately.

**The restore page stops listing non-tasks.** Values already identical to the
environment are a footnote, not a chore; `MAILER_DSN` and
`MESSENGER_TRANSPORT_DSN` left the export entirely, joining `DATABASE_URL` as
machine-local deployment choices the compose file owns; and the
old-encryption-key note folds behind one line for the only reader it
concerns — someone also restoring their old database.

**One browser, one remembered device.** With "remember this device", every
sign-in quietly added another row to Settings → Security: the 2FA library
re-registers a trusted browser on each login to extend its lifetime, and the
database-backed manager read that as "insert a sibling", orphaning the old
row and its cookie each time. The manager now renews the grant the presented
cookie actually resolves to — same row, same secret, expiry pushed out.
Matching is on the cookie alone, never user-agent and address, so two
identical laptops behind one router stay two rows; revoked grants are never
resurrected. Existing duplicates age out or clear with "Revoke every
remembered device".

**Settings looks finished.** The sidebar is grouped — Personal, Mail,
Calendar, Access — instead of thirteen equal links; file pickers look like
the buttons they are (Tailwind's preflight had stripped them bare); and every
native select shares one theme-aware look instead of six accidental ones,
including three that ignored the accent colour.

## v0.0.20 — 2026-08-08

**No schema changes.** Templates, one controller, and the calendar's layout
plumbing; nothing touches a table.

**A shared calendar is a calendar now.** The public share page grows the same
four views the owner has — month, week, day and agenda — with a switcher whose
every view is a plain link, because the page has no session to keep a choice
in and must not acquire one. The day-by-day list that used to trail under the
grid is gone; it became the agenda view. Days the link does not publish stay
visibly "not shared" in every view, paging still stops at the window's edges,
and busy/free links get all four views with nothing more to leak: the grid's
layout engine asks entries only for a start, an end and whether they run all
day, so it structurally cannot place a title anywhere.

**Both calendars now render through the same shells.** The toolbar, time grid
and agenda were extracted out of the authenticated calendar and both pages
draw through them — a restyle of one is a restyle of both, and the shared page
can no longer drift out of looking like plMail.

**Three bugs fell out of building it.** A shared page shown in the owner's
zone captioned a Berlin August as "July 2026" (the owner's own calendar was
shielded by middleware the public page rightly lacks); the shared agenda's
back-step was built from a phrase PHP accepts and does not mean, so stepping
backwards silently refused; and one long multi-day entry could cost a public
GET millions of date additions — the spread is now bounded by the page being
drawn. Switching views also keeps your place now: the switcher carries the day
you were looking at, not the first of the month it was filed under.

## v0.0.19 — 2026-08-07

**No schema changes.** Everything below lands in code and templates; nothing
touches a table.

**One meeting is one row, everywhere.** The time grid always collapsed a
meeting that lives on two calendars into a single chip with a multicoloured
dot; every other surface listed each copy separately. The same collapse now
runs in "Happening soon", on shared calendar pages and their `.ics` feeds, and
in the alert sweep — which was quietly the worst offender, sending two pushes
per reminder for a two-calendar meeting.

**Search sorts by date, and says so.** Results used to come back
relevance-ordered, which put a 2004 eBay mail above last week's. The default
is now most-recent, with a dropdown beside the pagination for most-relevant,
and the choice follows you to your other devices. Fixed underneath, because it
had to be: neither order was total, so paging through tied rows could show a
result twice and another never — measured at 148 distinct rows out of 150
before the fix. The search page also finally speaks the reader's language;
its headings and filter pills were English written into the template.

**Restoring a backup is uploading a backup.** The import used to print a wall
of `.env` lines to paste by hand, built on the assumption those values were
hand-maintained — they are generated on first run, so the import now writes
them where the entrypoint reads them and asks for one restart instead. The
database's own credentials left the export entirely: they are machine-local,
consumed once at initdb, and useless-to-harmful on any restore target. Old
backups still import; those entries are recognised and left alone. Beside the
export form there is now a generate button that fills both password fields and
puts the password on your clipboard — and where the browser withholds its
clipboard, the fields unmask instead of pretending.

**The badge that would not die is dead.** FrankenPHP keeps services alive
between requests, and four topbar helpers memoised per request without ever
being reset — so the first answer a worker computed was the answer every later
request got. That is the yellow log-alert outline that survived an emptied log
table, and on a multi-user install two of the four could show one user's
sidebar counts or calendar dot to another. All four now reset per request,
like the services that always did.

**Registered devices can be removed from settings.** A push subscription that
delivers perfectly well to a phone that no longer wants it — an old build's
leftover registration, a retired device — used to be a `DELETE` typed into
psql. It is now a button in Settings → Notifications, and the delivery log
deliberately keeps the removed device's history.

**Shared calendars and booking pages look like plMail now.** A share link
renders the app's own month grid — today marked, chips in place, prev/next —
in the owner's theme, falling back to Paper; booking pages get the same
treatment. What a link does not reveal is unchanged, and deliberately so:
chips on a busy/free page carry the owner's accent rather than calendar
colours, because colours would let a stranger group anonymous blocks by which
diary they came from. Also fixed: the share page had printed the literal text
`calendar.share.window` under its heading since the feature shipped.

## v0.0.18 — 2026-08-06

**Five schema changes, all additive and applied automatically on boot.**
`calendar_event` gains `my_participation` and `label_binding` gains
`graph_category_id` (neither backfilled — the reasons are in the migrations and
are worth reading before upgrading, because one of them changes when an
invitation appears on your calendar). New tables `fcm_config` and
`push_delivery`, and `message` gains two submission timestamps; those three
carry the push and scheduled-send features below and touch nothing existing.

**An invitation lands on the calendar when you accept it, and not before.**
*Yes* or *Maybe* puts it there, *No* takes it back off, and nothing about that is
one-way — the invitation itself never goes anywhere, so changing your mind later
moves the meeting on or off again. Until now every invitation was drawn the
moment the mail arrived, so a week of unanswered requests looked exactly like a
week somebody had agreed to. Only invitations addressed to you are affected: a
flight confirmation, a mirrored Google calendar and a meeting you organised
yourself all appear as they always did.

Invitations that arrived **before** this upgrade keep their old behaviour and
stay on the calendar whatever you answered — emptying somebody's calendar during
a `docker compose up` is not an acceptable way to ship a feature.
`app:backfill events` re-reads the mail and brings them into line.

**Events read out of mail land on your default calendar**, not on the
per-account one. A person has one diary; which mailbox a flight confirmation
happened to arrive at is a property of the message, not of the flight, and
filing by it split one day across as many calendars as you had accounts. The
per-account calendars still exist, and `Account::SETTING_CALENDAR_TARGET` still
points at one for anybody who wants the split.

**A meeting you put on a second calendar still hears its own updates.** Ticking
another calendar in the editor was recorded as an edit, which is the flag that
tells plMail to stop letting mail revise an event — so a shared meeting went
quiet, and the only symptom was a reschedule that never arrived. Sharing is not
correcting; and a later message now updates every copy rather than the one on
the calendar extraction happens to file to.

**All-day events are no longer two hours long, or on the wrong day.** They are
floating — a wall-clock date with no zone — and every reader was converting them
into one anyway, so an all-day invitation opened in the editor reading
"02:00 – 02:00" for anyone east of UTC and was drawn on two days. West of UTC the
same arithmetic moved it onto the day before. The container's own timezone was
never the problem and is still deliberately UTC.

**A twelve- or twenty-four-hour clock, in Settings → General.** It reaches every
time plMail prints — the mail list, a thread, a calendar chip, the day grid's
hour axis. The default is *Follow your language*, and it stays that state rather
than being silently converted into whichever format it currently resolves to.

**"Happening soon" lists what you typed as well as what was found in your mail.**
It filtered on "was this extracted?", which meant the one thing a person had put
in the calendar themselves was the one thing missing from the list telling them
what is coming up.

**Double-clicking empty space on the time grid creates an event there**, at the
quarter hour under the pointer. A single click deliberately does not — it cannot
be told apart from the end of a drag without arbitration nobody would enjoy
maintaining — and the **+** in each day heading stays, because a double-click is
not a gesture a keyboard has.

**Every day column lines up with its own heading again, and every chip prints
the hour it is drawn against.** The headings and the all-day band were siblings
above the scrolling hours, so they were as wide as the pane while the hours were
as wide as the pane minus the scrollbar; all three share one scroll container
now. Separately, a block was positioned against the calendar's zone while the
time inside it was printed on the reader's, so a meeting on the 15:00 line could
say 17:30. Both are the same kind of fault — one grid, two clocks — and neither
looked like an error.

**The calendar switch has three positions**: mail, split, calendar. The third is
a full-width calendar without navigating away from the mail — the mail is still
there behind it, so coming back is instant. The drag handle reaches the same two
ends: past the pane's limits it keeps moving with resistance, and letting go past
the threshold switches. The docked pane also draws the same time grid the page
does now, rather than a column list of its own, and choosing Week or Month
widens it to fit — animated, and only ever upward.

**Renaming a label no longer creates a second Outlook category.** With label sync
on, renaming a category that had come from Outlook created a new one under the
new name and left the old one standing, which the next sync imported back as a
label beside the renamed one. A master category is now addressed by the name it
had, its id is recorded when Outlook is read, and every message carrying the old
name is pushed again so it carries the new one. Also fixed alongside: a category
id was being written into the column that means "this label is an Exchange
folder", so a label plMail created as a tag started being pushed as a location.

**Push can reach a phone through Firebase now, beside Web Push.** Configured at
runtime under Admin → Push: paste your own Firebase project's service-account
key and its `google-services.json`, and the session starts advertising the
public half — which is how one stock Play-Store build of the Android app
configures itself against whichever instance it is signed into. No Firebase, no
Google: Web Push and pull-only still work exactly as before, and a client is
told honestly which transports this instance can actually deliver over.
Messages travel as data-only payloads; Google learns that something arrived and
when, never what it says.

**Every push attempt is on the record.** Admin → Push → Deliveries lists what
was sent to which device over which transport, with the outcome, the error name
that tells a dead phone from a Firebase outage, and the latency; your own
devices and their last delivery appear in Settings → Notifications. Nothing of
a payload beyond its type is stored, and the record outlives the subscription
it is about. Pruned after 30 days.

**A scheduled send is now something the server admits to.** `EmailSubmission/get`
answers a held submission as `pending` with its real release time, a cancelled
one as `canceled`, and the changes feed reports both transitions — so a mail
scheduled on one device is visible, and cancellable, from every other. Found
and fixed alongside: cancelling a mail that was never submitted used to arm a
flag that silently swallowed that draft's *next* send.

**JMAP grew the surface a native client was waiting for.** `Email/set` update
applies `attachments` instead of silently dropping them (a refused patch now
writes nothing at all, the subject beside it included); `EmailSubmission/set`
honours `identityId`, so sending as an alias actually sends as the alias;
scheduled send via the standard FUTURERELEASE envelope parameters, up to thirty
days; `Contact/autocomplete` serves recipient suggestions ranked by the same
query the web composer uses; `Appearance/get|set` syncs the theme a user chose;
the session states each account's sync window instead of leaving a client to
probe for it; and `CalendarEvent/query` expands recurrences server-side, so
drawing a month of a repeating series costs one round trip instead of
thirty-one. Every extension sits under a `urn:plmail:` URN and is documented in
`docs/CLIENT_DEVELOPMENT.md`.

**Your configuration fits in one file you can actually restore.** Admin →
Backup exports the instance's secrets and credentials — env values, the JWT
keypair, the provider and Firebase credentials, decrypted so they survive the
move to a machine with a different encryption key — sealed with a password of
yours (Argon2id + secretbox, format documented down to the shell one-liner that
opens it). Import shows its plan before touching anything, applies what it
honestly can, and prints the exact lines for what only you can place. A fresh
install offers the same restore on `/install`, before the first account exists
and never after.

## v0.0.17 — 2026-08-05

**Two schema changes, applied automatically on boot.** `calendar_event` gains a
`remote_instances` map and `calendar_event_occurrence` a start-only index;
`calendar_alert_delivery`, `calendar_share_link`, `calendar_share_link_calendar`,
`booking_page`, `booking_page_calendar` and `calendar_booking` are new tables.
All additive; nothing existing is altered or rewritten, and an install that
neither shares a calendar nor sets a reminder is unaffected by any of it.

**One deployment change, and read it before pulling.** `compose.yaml` now mounts
three named volumes it should always have mounted — `app_attachments`,
`app_raw` and `app_uploads`. If you have been running the stock `compose.yaml`,
your attachments and raw sources are currently inside the containers rather than
beside them, and **mounting an empty volume over that directory hides what is
there now**. Nothing is deleted, but the files stop being visible.

Before `docker compose up -d`, copy what the running containers hold:

```bash
docker compose cp php:/app/var/attachments ./attachments-backup
docker compose cp php:/app/var/raw ./raw-backup
```

then bring the stack up and copy them back into the new volumes:

```bash
docker compose cp ./attachments-backup/. php:/app/var/attachments
docker compose cp ./raw-backup/. php:/app/var/raw
```

An install that already used `compose.override.yaml` or `truenas.compose.yaml`
has these mounts and is unaffected.

### Fixed

- **Attachments downloaded fine and then 404'd, and everything vanished on a
  restart.** The stock `compose.yaml` — the file the README tells you to run —
  mounted no volume for `var/attachments`, `var/raw` or `var/uploads`. The
  workers write those during sync and the web container serves them back, so
  each kept its own copy in its own layer: the file was written by a process
  that was not the one answering the request, and both copies died with the next
  container recreate. `compose.override.yaml.dist` and `truenas.compose.yaml`
  both mount them, which is why this survived — the one path an operator
  actually follows was the one that did not. The same omission in the `.dist`
  cost real data once before; see the deployment note above for moving what is
  currently stranded.

- **A repeating event quietly ran out of dates, and its reminders with it.**
  Occurrences are drawn when an event is saved, two years ahead, and nothing
  moved that window afterwards — so a weekly standup created today reached six
  months in eighteen months' time and eventually stopped being drawn at all.
  Reminders read those rows, so they stopped too. Silently: the event still
  existed and still said it repeated weekly. `app:calendar:materialise` now rolls
  the horizon forward nightly. The query it runs had existed since the
  materialiser did, documented as exactly this sweep, and nothing had ever called
  it.

- **One mailbox with an unusable address could stop everybody's reminders.** The
  reminder mailer built its message outside its own error handling, and an
  account whose username is not an email address threw before anything was sent
  — ending the whole minute's sweep for every user on the install, once a
  minute.

- **The Microsoft setup wizard asked for three permissions out of the nine
  plMail requests.** Following it produced an account with no categories and no
  calendar, and Microsoft does not upgrade a token already issued when the
  permission is added later.

### Added

- **The week is a time grid you can drag events around on.** Hour rows, blocks
  sized by their real times, meetings that overlap sharing the width, and
  all-day events in a strip of their own rather than squashed against midnight.
  Drag one somewhere else to move it, drag its edge to make it longer. Dragging
  one occurrence of a repeating event asks the same question the editor does —
  this one, or all of them — and read-only calendars refuse the drop and say
  why. The docked pane keeps its column list: a 380px pane has no business
  drawing a grid.

- **Calendars go in and out as `.ics`.** Download one event or a whole calendar,
  upload a file into a calendar you pick, or subscribe to a published calendar
  by URL — `https://` or `webcal://` — and have it refreshed on the same
  schedule everything else is. A subscribed feed is read-only. Importing the
  same file twice updates rather than duplicates, and an event that arrived by
  invitation is recognised as the same meeting rather than added again.

- **Reminders.** Six one-click offsets — at the time, five, ten or thirty
  minutes, an hour, a day — plus any number of minutes you like, and as many
  reminders on one event as you want. They arrive as a browser notification or
  as mail. A reminder set on a repeating event fires for each occurrence, and a
  reminder never fires twice however many times a sweep is interrupted or
  replayed. Reminders travel to and from Google, Microsoft and CalDAV, and are
  written into an exported `.ics`.

  **A fresh install can deliver neither.** Browser notifications need the
  browser to have been given permission, and mail needs an account that can
  send. Until one of those exists, a reminder is stored and nothing arrives.

- **What is happening soon, beside the mail it came out of.** A control in the
  top bar, which appears only when there is something to show and wears the icon
  of whatever is next — a plane, a parcel — opens a list of the next fortnight's
  bookings, each naming the message it was read out of.

- **Calendars over JMAP**, under `urn:plmail:params:jmap:calendars`:
  `Calendar/get`, `CalendarEvent/get`, `CalendarEvent/query` and
  `CalendarEvent/set`. An edit made by a JMAP client reaches Google and
  Microsoft exactly as one made in the browser does. Client authors: an event id
  is the series rather than a dated occurrence, and a query must name a date
  window — see the handbook.

- **A handbook.** [The wiki](https://github.com/karatektus/pl_mail/wiki) now has
  34 pages: every feature and how to use it, installing on Docker, Linux, WSL2,
  macOS and NAS boxes, a complete environment-variable reference, step-by-step
  registration of the Google and Microsoft applications with the exact
  permissions to tick, and seven pages on how the internals work. It is
  generated from `docs/` in the repository, so it cannot drift from the code —
  and a build now fails when a command, a variable, a link or a page goes
  undocumented.

- **You can send somebody a link to your calendar without giving them an
  account.** Settings → Sharing makes one. Tick-boxes on the link decide what it
  says: with none of them ticked it shows only when you are busy, and each box
  adds one thing on top — the title, the location, the description, who is
  coming. You choose which calendars it covers and whether it shows a rolling
  window from today or two fixed dates, you can change all of that afterwards
  without the address breaking, and you can revoke it. An event you marked
  private stays a plain busy block whatever the link says, and one you marked
  secret does not appear at all. There is an `.ics` beside the page for people
  who would rather subscribe than look.

  **The address is shown once, when you create it.** It is a password to your
  diary, so plMail stores only a hash of it — the same treatment device pairing
  codes get. That means it cannot be shown to you a second time: copy it when it
  appears, and if you lose it, press regenerate, which gives the link a new
  address and kills the old one.

- **People can book an hour of your day.** A booking page publishes the weekdays
  and hours you are available, how long an appointment is, how much room to leave
  around one, how far ahead somebody may reach and which calendar the booking
  lands on. The times it offers are free against your real calendar — every
  calendar you nominate, including mirrored ones — so cancelling a meeting brings
  its hour back and moving one takes the gap with it. Whoever books gives a name,
  an address and optionally a note, and gets a confirmation with a calendar file
  attached.

  **Two people cannot take the same slot.** That is enforced by the database
  rather than by a check, so it holds when both of them press the button at the
  same instant; the second one is told the time has just gone and shown what is
  still free. A booked appointment appears in your calendar marked as one, and
  lands on the calendar you nominated — so if that calendar syncs to Google,
  Microsoft or a CalDAV server, the booking is on your phone as well.

- **An event can be put on another calendar, including one you sync.** The
  editor's calendar list is now every calendar you have, ticked wherever the
  meeting already is. Tick one it is not on and a copy is made there — on a
  mirrored calendar that means it is pushed to Google, Microsoft or your CalDAV
  server straight away, which is how a booking read out of an email gets onto
  the calendar on your phone. The copy is the same meeting rather than a second
  one, so the views still draw a single entry, and a later update from the
  organiser still reaches every copy of it.

### Changed

- **The event editor's calendar dropdown is gone.** The checkbox list does
  everything it did and one thing it could not, and two controls answering
  "which calendars is this on?" can disagree — a calendar picked in one and
  three boxes ticked in the other is a meeting asked to be in three places at
  once. Unticking a calendar still leaves that copy exactly as it was rather
  than removing it; Delete is what removes, and it reads the same ticks. Moving
  an event to a different calendar is therefore two steps now: tick the new one
  and save, then re-open, tick only the old one and delete.

## v0.0.16 — 2026-08-05

**Three schema changes, applied automatically on boot.** `calendar_event` gains a
sync state and a synced-at stamp, `calendar` gains a last-synced-at and a
last-sync-error, `calendar` also gains four push-registration columns, and
`event_proposal` is a new table. All additive; nothing is dropped or rewritten,
and an install that never connects a calendar is unaffected by any of it.

**Google and Microsoft accounts now ask for calendar permission at sign-in.**
Existing connections keep working for mail either way. To let them carry
calendars, enable the Google Calendar API and add the `.../auth/calendar` scope
in Google Cloud, or add the `Calendars.ReadWrite` delegated permission in Azure
— there is no second app to register and nothing to configure in plMail.

### Added

- **Calendars from Google, Microsoft and CalDAV, synced both ways.** Subscribe
  from Settings → Calendars: plMail asks the account what calendars it has, you
  tick the ones to mirror, and changes travel in both directions. Google and
  Microsoft ride the same sign-in you already use for mail. CalDAV is a
  connection of its own — give it an address and it finds the rest, with the
  option to reuse a mail account's stored password rather than typing an app
  password, off by default because most servers want a separate one anyway.
  Each mirrored calendar says where it comes from, whether it accepts writes,
  when it last synced and why it stopped, because a calendar that syncs on a
  sweep nobody watches is the only kind that can break silently.

- **A date in an ordinary email can be added to the calendar.** Not an
  invitation, just a sentence — "Termin wie vereinbart: 04.08.2026 um 14 Uhr",
  or "let's meet Saturday at 3pm". A card offers it, quoting the line it read so
  the guess can be judged at a glance, and nothing reaches the calendar until
  somebody says yes. German and English, explicit and relative dates, durations
  where they are stated. "Not an event" is remembered, so it is not offered
  again.

- **One occurrence of a repeating event can be changed on its own.** Saving or
  deleting a recurring event now asks whether you mean this one or all of them,
  and only asks when the event actually repeats. Moving a single occurrence
  leaves its siblings where they were — and choosing "all events" from an editor
  you opened on one occurrence applies what you *changed* rather than where that
  occurrence happened to be, so renaming a weekly meeting from next month's
  entry does not move the meeting to next month. A single-occurrence change
  reaches a CalDAV server; Google and Microsoft take the series but not yet the
  instance, so on those it stays local for now.

- **Google and Microsoft calendars can arrive by push rather than being waited
  for.** Where the installation has a public HTTPS address, a change made
  elsewhere shows up in seconds instead of at the next quarter-hour sweep.
  Google additionally refuses to open a channel unless the callback domain is
  verified in the Cloud project that owns the OAuth client; the admin
  diagnostics panel says so, and Microsoft needs no equivalent step. Without a
  public address nothing breaks and nothing needs configuring — calendars stay
  on the sweep, which remains the backstop either way.

- **One meeting that reached plMail twice is drawn once.** An invitation you
  were sent and the same meeting on a connected calendar are two entries for one
  appointment; the views now draw them as a single entry with a dot showing each
  calendar's colour. Editing it asks which copies to change — all of them by
  default, because that is what editing "the meeting" means — and a copy on a
  read-only calendar is listed but cannot be ticked. Untick one and it stops
  matching the others, so it separates back into its own entry, which is the
  point rather than a side effect. Copies that disagree are never merged: if one
  says two o'clock and the other says three, you see both, because a tidier
  screen is not worth hiding that from you.

- **The admin header says which build is running.** The release and the commit
  it was built from, on every admin page — the first thing worth knowing when a
  deployment behaves unlike the branch you are reading, and not otherwise
  discoverable now that migrations run on boot and `latest` moves. A checkout
  that was never built from a tag shows nothing rather than a placeholder.

- **An invitation can be answered from the mail it arrived in.** Invites were
  parsed, filed on a calendar and then unanswerable — the participants were read
  out of the `.ics` and written into the event, and nothing rendered them. A
  message carrying an invitation now shows a card above it: when it is, who
  called it, who else is coming and how they answered. Yes / Maybe / No records
  the answer and sends the organiser an iTIP `REPLY` — same UID, same SEQUENCE,
  one ATTENDEE line — which is what every other calendar reads to tick a name
  off. The card says what the answer was; the toast says whether it reached the
  organiser, because a reply that could not be sent leaves somebody holding a
  seat for a person who thinks they declined.

- **Calendars can be managed.** They carried a name, a colour, a time zone, a
  visibility flag and a default flag from the first pass, and nothing reached
  any of them: a user with four accounts had four calendars named after their
  usernames, all shown, with no way to say where a new event should land.
  Settings → Calendars now does all of it. A provisioned calendar cannot be
  deleted (it would only be created again) and the default can be neither
  deleted nor hidden (new events would vanish on save), so neither offers the
  button.

### Fixed

- **A recurring invitation or CalDAV event appeared exactly once.** The
  repeating rule was read and then stored unconverted, and the part of the
  application that draws occurrences reads the converted form — so a weekly
  meeting that arrived by mail, or lived on a CalDAV server, drew a single
  entry and nothing else. One conversion now serves all three sources; a rule
  that cannot be converted faithfully is still refused outright rather than
  half-applied, because an event on the wrong days is worse than one that does
  not repeat.

- **An instance moved out of its series landed in the wrong place, differently
  on every provider** — a duplicate on the new day from Google, the old time
  from Microsoft. Moved and cancelled instances are now carried as the
  per-instance exceptions the format has for them, and CalDAV writes them back,
  so editing a series' title no longer deletes every instance somebody moved.

- **An event accepted from a suggestion could be silently rewritten by a later
  email.** The protection was accidental: the guard only covered events that
  came out of extraction, and what actually kept mail off these was that plMail
  mints identifiers no sender collides with. A message carrying the same
  identifier overwrote the lot. Events a person decided on are now off limits
  to mail by rule, and the refused claim is still recorded.

- **An invitation from Google Calendar had no organiser, so it could not be
  answered.** The organiser is listed twice in a normal invite — once as
  ORGANIZER and again as an ATTENDEE, because they are going too — and the
  participants were keyed by address and written in that order, so the second
  line landed on top of the first and took the `owner` role with it. The event
  ended up with nobody marked as organiser, which is nobody to reply to, so the
  new invite card correctly offered no answer to what was in fact a perfectly
  ordinary invitation. Roles accumulate onto the address now instead of
  replacing each other. Existing events carry the old shape until
  `app:backfill events` re-reads the mail they came from.

- **Re-reading an invitation un-answered it.** The mail that asked the question
  says NEEDS-ACTION about you forever, so every re-run of extraction reset an
  RSVP that had already been sent — the organiser knew, and the screen did not.
  An answer already recorded is kept unless the incoming copy states a real one.

- **Throwing away an event extraction got wrong put it back.** Extraction is
  re-runnable by design, so deleting an event only lasted until the next
  backfill walked the mailbox again and re-created it from the same message.
  "Not an event" now records the refusal against the claim's dedup key — the
  table for it existed and nothing had ever written a row — so the dismissal
  survives a re-run and also catches the *next* message about the same booking.

- **Correcting an invitation's details un-answered it.** The event's canonical
  object is rebuilt from its columns on every edit, and the editor has no
  participants field, so fixing a meeting's title dropped the RSVP that had been
  stored on it. (Locally only — the organiser had been told long before.)

- **Typing into a modal that had just been opened could be silently discarded.**
  Closing a dialog cleared where the frame pointed but left the previous form in
  it, so the next open showed the last dialog until its own fetch landed —
  pre-filled, focusable and completely convincing. Anything typed in that window
  was thrown away when the real form arrived. The frame goes back to its spinner
  on close.

- **The sidebar's account chevron did not say whether it was open.** It is a
  disclosure button, and which way it will go stopped being guessable when the
  expanded account moved into the user's settings and started surviving
  navigation. It carries `aria-expanded` now, kept in step as it opens and
  closes.

- **Four mailbox pages ran an English word through the translator as a key.**
  The Inbox, Drafts, Sent and Trash titles asked for `'Inbox'|trans` rather than
  the key beside them in the catalogue, so they were untranslatable and reported
  as missing on every visit — and Starred asked for "Drafts", which is what it
  said. `thread_row.unread` was genuinely missing and read out as its own key by
  screen readers; a `|default` behind it could never have fired, because a
  missing translation comes back as the key and the key is not empty.

- **The pirate locale had no calendar at all.** `en_PI` was written before the
  calendar shipped and never caught up, so every string in it fell back to
  English. It has the lot now, including the new settings section.

## v0.0.15 — 2026-08-03

No schema change, no deployment change.

### Fixed

- **An Outlook account could stop syncing entirely.** `meetingMessageType` is
  declared on Graph's event-message type, not on the base message, and naming it
  unqualified does not get ignored — the whole `$select` is rejected, every
  message in the batch answers 400, and nothing arrives. It is asked for through
  the cast now, and a mailbox that refuses even that gets one retry without it
  and is remembered, rather than never syncing again.
- **Deleted Outlook drafts sat in Drafts and Trash at once.** Attaching a folder
  label relied on the source folder's removal notice to take the old one off,
  which only works while both carry the same message id — and personal
  outlook.com mailboxes do not reliably provide those. A folder move now
  replaces the location outright, which is what a single Exchange folder means.
- **A filter with no conditions claimed the whole mailbox.** "If this is any
  message → …" read the same whether the rule was scoped to one account or all
  of them, which is exactly the case where the account *is* the rule. It says
  which account now, and picking one updates the sentence and the match count
  immediately instead of waiting for the next edit.
- **A Graph subscription this install no longer has filled the log.** Microsoft
  keeps delivering to a registration it still holds — several times a second,
  for up to three days — and without the account there is nothing here that can
  cancel it. Logged once an hour per subscription instead of once per
  notification, so it stops burying everything else and lighting the unread-log
  ring over something nobody can act on.
- **"Matches 1 existing messages"** in the filter editor.

### Changed

- **The image tag is the first optional setting in the TrueNAS compose file**,
  with what each channel means: `:latest` for releases, `:main` for every merge,
  and the pinned forms. Also says the image tag carries no leading `v`, and that
  downgrading is not supported because migrations run forward on boot.
- **The README tour is current, and anyone can retake it.** `app:test:seed-demo`
  writes a believable demo mailbox, so the screenshots no longer depend on one
  person's installation; all eight are regenerated, and filters and the calendar
  are in the tour for the first time.

## v0.0.14 — 2026-08-03

No schema change, no deployment change.

### Fixed

- **The sidebar showed labels that no longer existed.** It was carried across
  navigations rather than re-rendered, so after a data reset the deleted labels
  stayed in the nav linking to 404s, and a label created or renamed anywhere
  else was invisible until a full reload. It renders per visit now; scroll
  position, open trees and the expanded account are restored rather than frozen,
  the last of those server-side so its folder rows are in the first paint
  instead of arriving one request later and blinking.
- **Sidebar highlights were inconsistent.** A hover left behind a square bar as
  it faded, accounts and their folder rows were rounded boxes rather than the
  pill every other row has, an open folder under an account was not highlighted
  at all, and a label's parents gave no sign that the open row was inside them.
- **An account's folder badges counted every account.** Clicking one lists that
  account's threads alone, so the badge beside it promised mail the list did not
  then show.
- **Snoozing an Outlook message went looking for a folder that does not exist.**
  Snoozed is plMail's own role with no Exchange counterpart, but every role was
  treated as folder-backed — so each snooze logged a missing `graphFolderId`,
  and a message both snoozed and archived looked like it was in two Exchange
  folders at once. Roles are folders only where the provider has one, and are
  never published as categories.
- **The unread-log ring cleared in the database and not on screen.** The log
  browser is a frame and the user menu is not inside it, so reading or clearing
  the log left the outline until the next full page render.
- **The calendar pane kept limits from a window size that was gone**, which is
  what made the resize handle stop at odd places after the window changed.
- **The settings pages had no live updates.** Their layout named a Stimulus
  controller — `mercure` — that stopped existing when the controllers were
  grouped into directories.
- **Admin and settings pages threw on every load.** `mail--mail-pane` was
  attached to every authenticated page but its targets exist only in the mailbox
  layout, so it failed to connect with "Missing target element".
- **A failed Microsoft Graph sub-request logged only its status.** Graph's own
  reason was sitting unread in the sub-response body, which is the difference
  between a diagnosable 400 and a mystery.

### Added

- **The thread view shows each message's own labels.** A rule that files a
  single reply was visible in the list row and nowhere in the thread it opened.

## v0.0.13 — 2026-08-03

No schema change, no deployment change. The encoding fix applies to mail synced
from now on — see the note below about what is already stored.

### Fixed

- **Mail composed in UTF-8 but labelled ISO-8859-1 arrived as "GrÃ¼ÃŸe".** A
  common sender bug, and every layer believed the label: IMAP bodies are
  converted inside webklex, which had no reason to doubt it, and encoded-word
  subjects went through iconv, which cannot. Bytes that are valid multi-byte
  UTF-8 are now read as UTF-8 whatever a single-byte label claims — the label
  is contradicted rather than merely ambiguous. Genuine latin-1, cp1252
  punctuation and multi-byte charsets like Shift_JIS are unaffected. Applies to
  mail synced from now on; messages already stored keep the bytes they were
  given.

### Added

- **The message details popover says why a message is in the tab it is in.**
  "Promotions — a bulk-mail header: list-unsubscribe", "Primary — you have
  written to this sender". Recomputed from the same class that made the call
  rather than stored, so it can never explain a decision the current rules no
  longer make.
- **A filter no longer needs a condition.** "Act on everything arriving in this
  account" is a rule people want and could not write: the editor demanded at
  least one condition and the validator rejected a rule without one. The editor
  still opens with one condition to fill in — removing it is now how the
  whole-account rule is written, and the panel says what it will then do. The
  live match count is scoped to the rule's account too, which it never was.

- **The user menu is outlined when something has been logged that nobody has
  read.** Amber for warnings, red once anything reached error, with the count
  in its tooltip and beside the Admin entry inside the menu, which then links
  straight to the log browser. Opening that browser is what marks them seen, so
  anything logged while it is open comes back. Admins only — for everyone else
  it would be an alarm about a screen they cannot open. The mark lives in the
  user's settings bag, so there is no schema change.

## v0.0.12 — 2026-08-03

No schema change. Dev stacks need the worker fix below applied to their own
`compose.override.yaml` before anything consumes a queue again.

### Added

- **Collapsed admin panels say what is inside them.** A shut card showed a
  heading and nothing else, so a collapsed dashboard was a list of nouns. Each
  now carries a one-line summary in its header — "2 down", "14 waiting",
  "3/4 armed" — coloured when it is worth noticing.
- **The queue panel is searchable and no longer unbounded.** The backlog list
  has a fixed height with its own scrollbar, loads the next page as that scroll
  reaches the end, and its filter box queries the whole queue rather than the
  page on screen: handler name, queue, or anything in the payload.
- **Search suggests as you type.** The box offers the operators it actually
  supports, and for `from:`, `to:` and `cc:` it completes against your contacts,
  so a sender can be found without remembering how they spell their address.
  Arrow keys move, Enter or Tab accepts, Escape dismisses the list before it
  clears the box.
- **`label:` and `cc:` search.** Both were half-built: `cc:` did nothing at all
  and `label:` was understood by the query builder but never parsed.

### Fixed

- **A collapsed panel beside an open one kept the open one's height.** Grid
  items stretch by default, so shutting one of a side-by-side pair left a 40px
  header inside a 400px outline.
- **Search operators failed silently.** `in:archive` was documented but never
  mapped, so it was dropped and the search answered with everything, unfiltered
  — as did any unknown value, like `is:important`. A half-typed `from:` did the
  same, matching every message through an empty LIKE. Unrecognised operators
  now fall through to free text, an incomplete one filters nothing, and
  `in:archive`, `in:snoozed` and the aliases `bin`, `deleted`, `archived` and
  `draft` are understood.

- **The dev override still described the pre-split worker.** v0.0.10 replaced
  `messenger-worker` with three services but left `compose.override.yaml.dist`
  defining the old one, so a dev stack ran a worker consuming only the retired
  `async` queue: sends and syncs queued up and nothing consumed them, with no
  error anywhere. Copy the new `.dist` over your `compose.override.yaml`, or
  add the three `worker-*` blocks to it, then
  `docker compose up -d --remove-orphans`.

## v0.0.11 — 2026-08-03

No schema change, no deployment change.

### Added

- **The queue panel says what the workers are doing.** It showed a depth and an
  age per queue, which cannot tell a queue that is empty from a queue that is
  stuck — both read as "nothing waiting". It now lists the messages a worker is
  holding right now, each with its handler, its payload and how long it has been
  held, above a per-queue breakdown of pending and in-flight counts, and the
  backlog behind them: what is waiting, what is merely scheduled for later, and
  how often each has already been retried. A message whose envelope no longer
  deserialises is listed as undecodable rather than skipped — it is stuck
  forever, and was previously invisible.

## v0.0.10 — 2026-08-03

**Deployment shape changes.** The single `messenger-worker` service is replaced
by three — `worker-export`, `worker-ingest`, `worker-maintenance` — so remove
the old one when upgrading. Roughly 150MB more resident in total. No schema
change.

### Fixed

- **Sending waited behind whatever the mailbox was doing.** Seventeen message
  types shared one queue and one worker, so they were ordered by arrival:
  pressing Send behind a Gmail batch meant waiting for the batch. Outgoing work
  now has its own queue and its own process, and retries faster than the rest —
  a relay refusing a connection clears in seconds, not minutes.
- **Learning contacts re-read the whole mailbox after every sync.** A sync that
  brought in twenty messages re-read all fifty thousand, hydrating whole
  messages — bodies, headers and search vectors included — to use five address
  fields. Measured on a real account at five and a half hours of database time
  a day, more than every other query on the server combined. Addresses are
  learned from each batch as it arrives; the full sweep is now
  `app:backfill contacts`, run when it is actually wanted.

## v0.0.9 — 2026-08-03

Mostly internal. **One schema change**: five tables gain an `updated_at`, added
nullable, backfilled from `created_at` and then made `NOT NULL`, so it is safe
on a populated database. Restart the workers after upgrading — several service
constructors changed and `messenger-worker` and `imap-supervisor` hold a
compiled container.

### Fixed

- **A draft created by an app went missing from Drafts.** A thread's labels are
  rebuilt from the messages it holds, and a freshly threaded draft was not in
  that collection yet, so the rebuild took back the Drafts label it had just
  been given. Drafts written in the browser were unaffected, which is why this
  survived so long.
- **Mail sent from an alias went out as the account's main address.** The
  composer wrote the chosen address onto the message and the save overwrote it
  one line later. Both the web composer and JMAP were affected.
- **`Mailbox/set` could destroy a mailbox that had just been given a child.**
  The refusal counts children, and a child created earlier in the same request
  was not visible to the count — which JMAP makes reachable, since create and
  destroy travel together.
- **Editing a draft in place, popping the editor out and closing it** left the
  conversation without that draft's row until a reload.
- **A phone opened on the calendar** if the calendar pane had ever been opened
  on a desktop. The pane state is one preference shared by every device, and
  below 1024px the pane replaces the mail rather than sitting beside it.
- Settings gets the same edge-to-edge cards on mobile the admin area got.

### Added

- **Statement statistics are enabled at boot**, so a slow query reported next
  week is explained by numbers that were already being collected. Where the
  database refuses, the admin Database tab offers a button; where the server
  cannot support it at all, it says so instead.

### Changed

- Every entity is properties and hooks — no getters, no setters, no fluent
  setters — and every one takes `TimestampableTrait` rather than tracking its
  own timestamps. Both timestamps are non-nullable, which removed 35 suppressed
  type mismatches.
- All SQL, DQL and DBAL now lives in a repository, and every hand-written query
  that stayed carries a comment saying why it could not be a plain Doctrine
  call.
- Controllers hold their actions and the responses those actions share.
  Everything else moved into services grouped by purpose — draft persistence,
  attachments, reply building, JMAP change announcements, OAuth state — several
  of which were previously implemented twice, once for the browser and once for
  JMAP.

## v0.0.8 — 2026-08-03

### Fixed

- **A Gmail rate limit looked exactly like a permissions failure, and was fatal
  either way.** Gmail signals throttling with 403 rather than 429, and the only
  place it names the cause is a response body that was never read — so a
  transient quota rejection killed an entire account sync and reported nothing
  but a status. Quota rejections are now retried with a real backoff;
  permissions failures and daily-limit breaches are never retried.
- **Archive, star and trash could vanish silently on Gmail, Outlook and IMAP.**
  All three outgoing pushes swallowed their failures, on the stated grounds that
  the next sync would reconcile the difference. It does not: sync reads the
  server's state *in*, so a change that never arrived leaves nothing to
  reconcile from and the next pass overwrites it. The action disappeared from
  the account while still looking applied on screen. Transient failures are now
  retried; only a refusal that would repeat forever is dropped. An IMAP
  authentication failure is deliberately still not retried — repeated rejected
  logins get mailboxes locked and hosts banned.
- **Retries gave up after seven seconds.** The async queue retried at 1s, 2s and
  4s, which is not a backoff for anything that clears in minutes. Now 5s to 300s
  over five attempts. A send that hits a briefly-unavailable relay may now take
  up to ~8 minutes to go out instead of failing outright.
- **The admin log view showed no log messages on a phone.** The message column
  was zero pixels wide at every phone width and the overflow was clipped rather
  than scrolled, so each entry displayed its level, time and source and nothing
  else. Entries also gained a copy button that copies the whole entry, and works
  over plain HTTP where the clipboard API is unavailable.
- **The admin area spent a third of a phone screen on nested padding**, because
  the page padded its content and every section inside it padded its own.

## v0.0.7 — 2026-08-02

### Fixed

- **Three of the four services failed to start on any deploy that carried a
  migration.** php, imap-supervisor, messenger-worker and scheduler run the same
  entrypoint and all migrate on boot, within milliseconds of each other against
  one database. All four read the migration ledger before any of them writes to
  it, so all four try to apply the same migration; one succeeds and the rest die
  on `column "timezone" of relation "user" already exists`, which under `set -e`
  is a container that never comes up. Boot migrations now run under a Postgres
  advisory lock held for the whole run (`app:db:migrate`), so the containers
  that lose the race wait, find the ledger already current, and start normally.
  Nothing to do on upgrade.

## v0.0.6 — 2026-08-02

### Added

- **The inbox categories cross JMAP.** plMail has classified inbox mail the way
  Gmail does for a long time and the web has had a tab bar over it, but no
  client except the browser could see any of it. `Thread.category` carries the
  conversation's resolved value, `Email.category` the raw per-message signal it
  was derived from, and `Email/query` gained a `threadCategory` filter so a tab
  is narrowed by the server rather than sieved from a page. Only the thread
  value is filterable: offering the per-message one would put a newsletter
  somebody answered into two tabs where the web shows it in one.
- **A timezone, per user.** Settings → General now carries one, and everything
  the app renders honours it. New installs fall back to `APP_DEFAULT_TIMEZONE`
  rather than to the container's clock, which is how this went unnoticed.

### Fixed

- **Every date rendered two hours early for anyone outside UTC.** PHP's default
  timezone is UTC, Twig was never told otherwise, and not one `|date()` call in
  the templates passed a zone — so a correct UTC instant was printed verbatim
  and an 11:00 appointment read 09:00 in Berlin. Mail dates and extracted
  calendar events both.
- **IMAP stored the sender's wall clock, not the instant.** The `Date:` header's
  offset was parsed and then kept, and Doctrine writes a datetime in whatever
  zone the object carries, so a `+0200` message landed in the column two hours
  ahead of the UTC it claimed to be. Gmail and Graph already normalised; IMAP
  now agrees with them. **Rows written before this are wrong by the sender's
  offset**; a resync corrects them and no migration is attempted.
- **Mail written or sent from the web never reached JMAP clients.** Composing,
  sending and discarding all changed messages without recording anything in the
  change log, so a phone learned about them only if something else happened to
  move the state. A draft that became sent mail stayed a draft on the device
  indefinitely.
- **`app:test:seed-mail` could not be used to test sync**, because it wrote
  messages without advancing the Email state — so `Email/changes` never
  mentioned them and no push fired however much mail it created. This is what
  made a genuinely broken client sync look like a server changelog gap.

## v0.0.5 — 2026-08-01

### Added

- **schema.org extraction.** Reads the markup booking confirmations already
  carry, so flights, parcels, hotels and restaurant bookings reach the calendar
  without an invite attached.
- An admin reset ladder, behind a door in the admin panel, exposing the reset
  the console command already had.

### Fixed

- **Mail arriving in the wrong alphabet, or not arriving at all**, and one 8-bit
  header byte costing the whole message.

## v0.0.4 — 2026-08-01

### Fixed

- **A batch with two copies of one invite killed the whole run.** Found on a
  real mailbox, 200 messages into `app:backfill events`; three defects that
  compounded, starting with an invite arriving twice creating two events.

## v0.0.3 — 2026-08-01

### Added

- **Calendar invites on the calendar.** Extraction end to end for the one source
  that is not a guess: a `text/calendar` part becomes a `CalendarEvent`, and the
  confirm/change/cancel life of a booking resolves to one row rather than three.

### Fixed

- The two API providers lost calendar invites at ingest.

## v0.0.2 — 2026-08-01

### Added

- **A look.** Paper and a retuned Dark, a rebuilt mail row, themed toasts and
  tooltips, and a Dark reworked into a dim room rather than flat backgrounds
  that painted nothing.
- **The calendar**, docked beside the mail and resizable against it, over a new
  data layer of JSCalendar events with materialised occurrences.
- **`Mailbox.color` over JMAP** — nine tokens or null, refused with
  `invalidProperties` rather than dropped, and accepted on create as well as
  update. Outlook and Gmail label colours are mapped onto the same vocabulary by
  hue, so a real account arrives already coloured.
- **A push service**, so Android does not have to mean Google; the push URL is
  derived from the host set at first boot.

### Changed

- The three sync paths share one post-ingest sequence instead of three.

## v0.0.1 — 2026-07-31

### Added

- **Snooze.** Put a conversation away and have it come back later — from the
  message row, the list toolbar, or a JMAP client via the `Thread/set`
  extension. Snoozed conversations get their own sidebar entry and view.
  `app:mail:wake-snoozed` runs every minute to bring them back.
- **JMAP draft attachments.** `Email/set` now accepts uploaded blobs in
  `attachments`, per RFC 8621; drafts created over JMAP previously dropped them
  silently.
- **Login throttling.** Five attempts per account per fifteen minutes on the
  password form, and a separate limit on the two-factor code form — which the
  firewall's throttling does not cover, and which is the form an attacker
  reaches holding a stolen password.
- **Admin user management.** plMail's data model has always been multi-user, but
  there was no way to create the second user: the setup wizard and `app:setup`
  both make the first one and then refuse. Admin → Users adds, edits, promotes
  and removes people. An administrator deliberately cannot change an existing
  user's password or remove anyone's second factor — both would make an admin
  session a way into someone else's mailbox.
- **`GET /healthz`** — reports database, queue and worker health without a
  session, so Docker healthchecks and uptime monitors can use it. The image's
  healthcheck now points here instead of at Caddy's metrics port, which
  answered before PHP could reach the database.
- **`app:backup`** — one command for the database dump, the stored files and
  the generated secrets, and it says explicitly when `APP_ENCRYPTION_KEY` is
  *not* in the backup because the install supplies it from the environment.
- **PHPStan** at level 5, with a baseline, in CI. **Dependabot** for composer,
  npm and GitHub Actions.
- **LICENSE.** The project has always said AGPL-3.0 in its README; the licence
  text is now actually distributed with it, and `composer.json` no longer
  contradicts it by claiming "proprietary".

### Fixed

- **Snoozing from the web UI did nothing but set a timer.** No labels moved and
  nothing propagated, so the conversation stayed in the inbox — locally and at
  the provider — while its row vanished from the list. The sweep would then
  "wake" a thread that had never left.
- **The snooze button on a message row cleared a snooze instead of setting
  one**, and the toolbar's snoozed everything to a hardcoded 8am tomorrow.
- **"Test connection" in account settings crashed** with an `ArgumentCountError`
  whenever the form was incomplete or the password blank — the two cases it
  exists to report.
- **The admin Graph category sync caught an exception class that does not
  exist**, so Graph failures propagated instead of degrading quietly.
- **User search in the admin area returned everything**, and soft-deleted users
  were visible to every query that claimed to exclude them. Both were the same
  mistake: a Doctrine expression built and never passed to `andWhere()`.
- **Gmail label changes ran inside the HTTP request.**
  `ApplyGmailLabelsMessage` was never routed to the async transport, so archive,
  trash, star and mark-read made a live Google API call while the user waited —
  where the IMAP and Graph equivalents were queued. A Gmail outage surfaced as a
  500 on a UI action rather than a retry on the worker.
- **JMAP `Email/set` rejected valid mailbox patches.** Current `mailboxIds` were
  emitted as label ids where the protocol expects per-account binding ids; since
  both are autoincrement ints, a patch removing one mailbox failed with "No such
  Mailbox".

### Changed

- `latest` is published from release tags only. It previously followed every
  push to `main`, which meant the tag the README tells people to pull could
  carry unreviewed work and automatic schema migrations.
- The login form no longer shows a "forgot password?" link. There is no reset
  flow — recovery is a console command — and the link was an `href="#"` that did
  nothing.
