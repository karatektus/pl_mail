# Tasks

plMail keeps your tasks beside your mail. Every user gets a list of their own to start with, and a
list can also sync two ways with any CalDAV server that holds tasks, such as Nextcloud Tasks.

The tasks are one of the panes of your workspace (see [Workspaces](workspaces.md)): add them beside
the mail, or above or below the calendar, from any pane's **⋯** menu or in edit mode. Drag the gap
beside the pane to resize it. On a phone, **Tasks** in the menu opens them at full width, as does the
page `/tasks`.

## Adding and finishing

Type in the field at the top of the pane and press Enter. Clicking into the field brings up buttons
for a due date, a reminder and a repeat, so you can set those as you add it. Without a date, a task
added on **My Day** is due today, so that it stays where you typed it; one added in a list has none.

Tick the circle to finish a task. Click anywhere else on the row to open it, where you can change its
title, notes, due date, reminder and repeat, work through its steps and add files. An
address in a title or in the notes (`https://…` or `www.…`) becomes a link.

## My Day

**My Day** fills itself. It shows what is due, starts or reminds you today, with overdue tasks as a
group of their own above. A task you finish today stays there, crossed out, until midnight. Today
means today on your clock: **Settings → Appearance** decides the zone, not the server's.

You cannot pin a task to My Day by hand. Give it a date instead.

## Lists and filters

**All** shows every list together, and each list has its own pill beside it. The gear at the top of the
pane opens **Lists**, where you make a list, rename or recolour one, delete one, and drag them (or
move them with the arrows) into the order the pane shows them in. Its star says
where a task lands when nothing else was picked (on **My Day**, on **All**); press another list's star
to send new tasks there. A task changes list in its dialog, under **List**; moving one off or onto a
synced list deletes it where it was and creates it where it goes, with its steps. Deleting a list
deletes its tasks. The first list, where a task lands when nothing else was picked, cannot be deleted,
and neither can a synced one.

A list shows all its tasks at once, grouped into overdue, today, upcoming, no date and completed.
Each group folds away, so a long one does not push the others out of view.

## Steps

A task can have steps: a short checklist inside it, one level deep. Start typing in the field and
another appears under it; an empty field is not saved, and a filled one is a step once you save the
dialog. Tick a step you are typing and it is saved as done, and its × takes it away again. The steps
a task already has are fields in the dialog too: change one and save. Delete a step with the × beside
it; emptying its field leaves the title as it was.

The row shows how far you are, as in **2 of 5**. The arrow at its end unfolds the steps, so you can tick
them off without opening the task, and the row stays unfolded in that browser tab until you finish the
task. A tick shows at once; the confirmation comes once it is saved, and if saving fails the tick is
taken back and you are told. Finishing the last step does not finish the task; that stays your call.

## Repeating tasks

Choose **Repeat** when you open a task. Ticking a repeating task off does not finish it: it moves to
its next date in the future, and its steps open again. A weekly task ticked three weeks late goes to
next week, not to a date that has already passed.

A schedule from another app that plMail has no word for, such as Mondays and Wednesdays, shows as
**Keep the current schedule** and stays as it is. A schedule with an end, by date or by number of
times, ends: the last tick finishes the task.

Each tick leaves a finished entry for that date: under **Completed**, and on **My Day** until midnight.
Unticking the newest entry puts the task back on that date; unticking an older one only removes it.
The entries stay in plMail. A synced list does not get them, so other apps see only the task moving on,
and deleting the task deletes its entries.

## Reminders

A task can have one reminder, at a date and time you choose. It arrives as a browser or phone
notification, like a calendar reminder, so it needs notifications to be on: see
[Reminders](calendar-alerts.md) for what that takes. A reminder that comes due while the server is
down goes out late if it is less than an hour late, and not at all after that.

A repeating task's reminder moves with it, keeping its distance from the due date.

## Files

Open a task to attach files of up to 25 MB. They stay in plMail, and download only for you.

On a synced list, other apps see a link back to the file, which asks for a plMail sign-in. If such an
app drops the link, the file stays here, and the next change puts the link back.

Files that another app linked to show up too, and open from where they are. plMail keeps them on the
task but does not hold a copy.

## Syncing with a server

First connect the server under **Settings → Calendars**, as for a [connected
calendar](calendar-sync.md): the same connection serves both. The gear at the top of the pane then
offers **Sync lists from a server**. Pick the connection there, tick the lists to keep in sync, and
pick each one's colour beside it. A server's calendars
that hold only events are not offered. Lists that hold only tasks come first, then calendars that
can hold tasks as well, which on Nextcloud is every calendar made or imported in its Calendar app.

Changes you make go to the server within moments. Changes made elsewhere arrive every fifteen minutes
or so; there is no push for tasks.

What plMail shows and changes: the title, notes, due date, status, repeat, first reminder
and steps. It also reads the start date and priority, and uses the start date for My Day, but has no
field for either. Everything else another app put on a task, such as categories, stays there when you edit it here.

If the server and plMail both changed a task, the server's version wins. A list you may only read on
the server shows a padlock, and nothing on it can be changed here.

Unticking a list removes it and its tasks from plMail. They stay on the server.

### Microsoft To Do

A Microsoft account brings its To Do lists with the same sign-in once its **Tasks** switch is on.
Tick **Tasks** when you connect the account, or switch it on later under **Settings → Accounts**
and allow it at Microsoft. **Sync lists from a server** behind the gear then offers the account beside any CalDAV connection,
with its default list **Tasks** ticked the first time. Switched off, its lists stay as they are and
nothing is synced until it is on again. The
**Flagged email** list is not offered, since flagged mail is already flagged here. An account
connected before plMail asked for To Do gets a notice in **Settings → Account health** whose button
signs in once more and adds it; the administrator first adds `Tasks.ReadWrite` to the app
registration (see [Microsoft](../providers/microsoft.md)).

Microsoft To Do works a little differently, and plMail follows it:

- **A due date is a day.** To Do keeps no time of day, so the time field is greyed out on its lists.
  The reminder keeps its time.
- **Microsoft moves a repeating task on.** Ticking one off sends the tick; To Do then moves the task
  to its next date and keeps a finished copy, and both arrive with the next sync.
- **Steps are To Do's steps**, and travel with their task.
- **Files stay in plMail.** Microsoft refused every file plMail tried to attach, so a file added here
  is kept here, and files added in To Do stay there.
- **Categories, importance, the start date and notes written as formatted text** stay as they are
  in To Do when you edit a task here, as long as you do not change the notes.

Google Tasks and plMail's own CalDAV and JMAP servers do not carry tasks yet, so lists that live only
in plMail do not reach your phone.

### Moving a list into plMail

Microsoft retires the To Do apps on 30 June 2027, and a CalDAV server can go away too. To keep a
synced list's tasks in plMail for good, open **Lists** behind the gear and press the transfer button
beside the synced list. Pick where the tasks go, a new list with the same name and colour or one of
your own lists, and press **Transfer**.

- Every task moves, open and finished, with its steps, notes, dates, repeat, reminder and files.
- Nothing is deleted on the server. plMail stops syncing the list afterwards, so nothing shows up
  twice.
- What only other apps understand, such as links to other items in To Do, is not kept.
- plMail first fetches the latest changes from the server. If the server cannot be reached, nothing
  moves, and **Transfer anyway** moves what plMail has from its last sync.

## Commands

`app:tasks:sync` queues a sync of connected lists, and `app:tasks:alerts` sends the reminders that have
come due. The scheduler runs both.

```bash
docker compose exec php php bin/console app:tasks:sync
```

```bash
docker compose exec php php bin/console app:tasks:alerts --dry-run
```

## Things that bite

- **A link to a file needs a plMail sign-in, and needs an address.** Other apps cannot open it without
  one. An install that has not recorded its public address sends no link at all until it has.
- **Files inside a task from another app are not listed.** A file embedded in the task itself, rather
  than linked, stays on the server and survives your edits, but plMail does not show it.
- **How other apps treat steps, repeats and links has not been tried against each app.** Nextcloud
  Tasks has been tried both ways: steps, repeats, reminders, files and its own priority, tags and
  progress all survive. tasks.org and Apple Reminders have not been tested yet. Try a list you can
  afford to lose first.
- **A ticked repeating task on a To Do list shows as done until the next sync.** Microsoft moves it to
  its next date when the tick arrives, and plMail learns of that with the next sync, which follows
  within moments when the worker is running.
- **Only a personal Microsoft account has been tried.** A work or school account should behave the
  same, but has not been tested.
- **Two apps can both remind you.** A reminder on a synced task can fire here and in another app that
  reads the same task.
- **A task with no steps of its own can still be a step elsewhere.** A step nested more than one level
  deep in another app shows under its top task here. A step whose parent is not on the list shows as
  a task of its own.
