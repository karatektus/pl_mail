# Workspaces

The area beside the sidebar is yours to arrange. It holds five kinds of pane — the **Mail list**, the
**Message**, the **Calendar**, **Contacts** and **Tasks** — side by side, above and below each
other, in any order. An arrangement is a **workspace**, and you can keep up to nine of them, for
example one for going through new mail and one for planning the week.

Each pane appears at most once in a workspace, except the calendar: a workspace can show up to four
calendars, each on its own view — this week beside this month, say. Choosing a view in one leaves the
others as they are, and each keeps its view the next time you open the workspace. **Split right**,
**Split below**, **Show instead** and edit mode's tray offer another calendar while there is room for
one. Two mail lists side by side (the Inbox beside Sent) are not possible yet.

## The pane menu

Every pane has a **⋯** button at the end of its header. Its menu does everything to that pane:

- **Split right** and **Split below** add a pane beside it or under it. The submenu offers the
  panes the workspace does not show yet. If the pane is too small to hold both at a usable size,
  plMail says so instead of squeezing them; make it bigger first, or close another pane.
- **Show instead** puts a different pane in its place. Choosing one that is already elsewhere in
  the workspace swaps the two.
- **Move** left, up, right or down swaps it with the pane next to it on that side. When there is no
  pane further that way, it leaves its group and becomes a full-height column (left, right) or a
  full-width row (up, down) at that edge of the window.
- **Full width** gives the pane the whole area; **Back to the layout** (also the ↙ button in its
  header) returns it.
- **Close pane** removes it from the workspace. The last pane cannot be closed; use **Show
  instead**.

Drag the gap between two panes to resize them, or focus it and use the arrow keys. A double-click
on it makes the two sides equal.

### Keyboard

| Keys | What they do |
| --- | --- |
| ⌥ + ← ↑ → ↓ (Alt on Windows and Linux) | Focus the next pane that way |
| ⌥ ⇧ + ← ↑ → ↓ | Move the focused pane that way |
| ⌥ \\ and ⌥ - | Split the focused pane right, or below |
| ⌥ ↩ | Full width, and back |
| ⌥ W | Close the focused pane |
| ⌥ 1 … ⌥ 9 | Switch to workspace 1 to 9 |
| ⌥ E | Edit the workspace, and save it |
| ⌥ L | Open the Layout menu |

The focused pane is the one under the mouse, or the one you last clicked or moved to with ⌥ and an
arrow. A pane that a shortcut moves out from under the mouse stays focused, so ⌥ ⇧ ← twice moves the
same pane twice, until you move the mouse again. None of them act while you are typing in a field.

## Without a Message pane

A workspace without a **Message** pane opens a conversation in place of the list, with a back
arrow to the list, as plMail always did on a phone. With one, the conversation opens beside the
list and the list stays where it is.

## Workspaces

Your workspaces are the tabs in the top bar; click one, or press ⌥ 1 to ⌥ 9. The **Layout** button
next to them opens the Layout menu:

- **Workspaces** lists them with an outline of each.
- **Start from a shape** offers eight arrangements: one pane, side by side, three columns, above and
  below, list + stack, top + two, list/stack/side and four. Pick one, choose what goes in each
  slot, and either **Use for this workspace** or **Save as new workspace**.
- **Edit this workspace** opens edit mode, and **New workspace** starts one as a copy of the
  current one, in edit mode.

### Edit mode

In edit mode the top bar becomes the edit bar, with the workspace's name and **Delete workspace**,
**Cancel** and **Save workspace**. Each pane shows a card with what it shows, a grip and a remove
button, and how much of the area it takes. Changes show as you make them:

- Click a pane to select it. **+ Right** and **+ Below** on the selected pane add one beside it or
  under it.
- Drag a pane by its grip onto another pane: near an edge it goes beside it on that side, in the
  middle the two swap.
- The tray at the bottom holds the panes the workspace does not show. Drag one onto a pane the same
  way, or click it to add it beside the selected pane.
- Choose a different pane from a card's list to show it there instead.

**Save workspace** keeps the arrangement. A pane that ended up narrower than it can be used at gets
room from its neighbours when you save. **Cancel** puts back what was there when edit mode started.

## Accounts, sidebar and what is new

In edit mode, **Groups & sidebar** in the edit bar opens the workspace's settings. Its choices are
saved with **Save workspace**, like the rest of the edit.

- **Account groups** decide which accounts the workspace shows. Pick none and it shows every
  account, as before. Pick one or more and the mail list, **Starred**, **Unread** and the other
  lists, the sidebar's rows and counts, and Compose's first **From** keep to their accounts. A list
  that names its account (one account's inbox, its All Mail) shows what it names. The groups are the
  ones under **Settings → Inbox → Account groups**: one or more per workspace, and an account can be
  in several groups.
- **Sidebar** is **Mail (built in)** — the sidebar plMail always had — **No sidebar**, or one of your
  own. **New sidebar…** starts an empty one and **Copy of the Mail sidebar…** one with the same rows.
  A sidebar of your own is a list of rows: fixed links (a system row such as **Starred**, an
  account's inbox, an account group, a label, a calendar, a task list), headings, the clock, and
  rows that grow by themselves (**Inboxes**, **Accounts and their folders**, **All labels**, **All
  calendars**, **All task lists**), which show what you have now, narrowed to the workspace's
  accounts. Sidebars are shared: two workspaces can use one, and changing it changes it in both.
- **Sidebar links open in** chooses which calendar a calendar or task list row opens in, when the
  workspace has more than one. **When this workspace has no pane for a link** either switches to a
  workspace that has one, preferably one showing that account, or adds the pane at the right-hand
  edge.

A calendar or task list without an account — a local one, a subscription — belongs to one
workspace: the first, unless you choose another in its settings (**Workspace**).

### The calendar and tasks in a workspace of some accounts

The calendar and tasks panes have a three-way switch in their header: **Workspace** shows only the
workspace's calendars or lists, **+ Others** (the default) shows the rest too, faint, so you see
what else takes your time, and **All** shows everything alike. Each calendar pane keeps its own
choice; the tasks pane has one. When a header is short of room, the switch and the calendar's views
fold into menus.

### New since you last looked

A workspace tab shows how much is new in it since you last had it on screen: mail that arrived in
its inbox, events added to its calendars, tasks added to its lists — each only if the workspace has
that pane. It is not the unread count: mail read on your phone still counts, and opening the
workspace clears it, read or not. Mail you sent does not count. A dot on the tab says something in
it starts or falls due within the hour that had not yet when you last looked. The count is kept for
your account, so a visit on one device clears it on the others.

### Search

`space:Work` limits a search to a workspace's accounts, by its name (quote a name with spaces).
**Settings → Search → Start search in the workspace** starts every search that way, as a chip one
backspace takes off.

## What is remembered where

Your workspaces — their names, which panes they show and how they are arranged, their account
groups and sidebars — belong to your account and are the same in every browser you sign in to. They
are part of a [configuration backup](../install/config-backup.md); which calendar or task list
without an account is in which workspace is not, and those go back to the first.

Where you dragged the dividers, and which pane is at full width, belong to the device: a laptop and
a large monitor can keep their own sizes of the same workspace. A new device starts from the
proportions the workspace was saved with.

Each workspace's panes are its own. A calendar remembers its view for your account, so a month in
one workspace and an agenda in another each stay put. **Contacts** and **Tasks** remember where you
left them — which task list, which contact or group — for each workspace on this device: switch
away and back, or reload, and they are where that workspace left them. A new device starts them at
the top.

## On a phone and a small window

Below 1024 pixels wide the panes take turns: a row of tabs above them names the panes of the
workspace, and one is shown at a time. The message is not a tab; it opens in place of the list.
Edit mode and the Layout menu are for a wider window.

## The first workspace

The first time you open plMail after the update, your first workspace is the layout you had: the
mail, the message beside it if the reading pane was on, and the calendar, contacts and tasks if they
were open. A new account starts with the mail and the calendar beside it.

The top bar's calendar, contacts and tasks buttons, their **Settings → Calendar behaviour** and
**Contacts behaviour** choices, and the **Reading pane** setting were removed: the pane menu and
your workspaces do what they did. **Contacts** and **Tasks** are still in the drawer on a phone,
and their pages (`/contacts`, `/tasks`) show the pane at full width.

## Things that bite

- **Choosing a folder from a workspace without the mail list switches workspace.** plMail goes to
  the first workspace (in the order of the tabs) that has the list; its tab lights up. The same goes
  for a folder chosen in the sidebar of Settings or the admin panel; reloading the page does not. If none of your
  workspaces has one, the list is shown at full width instead; **Back to the layout** returns to
  the workspace as you arranged it.
- **On `/contacts` and `/tasks`, leaving full width goes to the Inbox.** Those pages draw one pane
  and no list behind it, so there is no layout to go back to in place.
- **Sizes are kept in a cookie of this browser.** Clearing the site's cookies, or a private
  window, starts from the proportions the workspace was saved with. The workspaces themselves are
  not affected.
- **In edit mode a pane can be narrower than it can be used at,** because moving panes around
  passes through such states. Saving gives each such pane room from its neighbours where they have
  some to spare; on a small window there may not be enough, and a pane stays cramped until you close
  one.
- **A workspace whose account groups are all deleted shows every account,** not none: an empty
  workspace would read as lost mail.
- **Switching between workspaces with different accounts or sidebars** draws the sidebar, the list
  and the calendar and tasks panes again in one go; the old ones are dimmed for that moment.
- **⌥ shortcuts do nothing while a field has the focus,** where ⌥ moves by word or types special
  characters. Click into the list or a pane first.
