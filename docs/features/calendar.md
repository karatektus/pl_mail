# Calendar

plMail keeps a calendar beside the mail rather than in a separate application, because most of
what ends up on a calendar arrives as mail in the first place. This page covers the calendar
itself: the four views, the two shapes it takes on screen, and everything the event editor can
do. Invitations and events read out of messages are in
[Invitations and events from mail](calendar-invitations.md); reminders are in
[Reminders](calendar-alerts.md); mirroring somebody else's calendar is in
[Connected calendars](calendar-sync.md).

![The calendar](../screenshots/calendar.png)

## The two shapes

The calendar is one feature rendered two ways, and which one you get depends on where you opened
it from rather than on any setting.

**Docked beside the mail.** The calendar button in the topbar slides a pane in next to the message
list. It is a sibling of the mail pane, not an overlay, so the two split the row between them — the
handle in the middle moves the boundary and one grows exactly as much as the other shrinks. Drag
it, or focus it and use the arrow keys; double-click puts it back. The width is remembered per
user between 320 and 900 pixels, defaulting to 380, and it is written into the page server-side so
the pane is already the right size on the first paint instead of jumping once the browser catches
up.

Below 1024px wide the row cannot hold a sidebar, a readable message list and a readable calendar at
once, so the pane takes the whole row and the mail steps aside. It is the same pane and the same
toggle — closing it puts the mail back exactly as it was, with no navigation in between. Below
768px there is no pane at all and the calendar is its own page.

**Its own page.** `/calendar` replaces the mail view entirely. This is what a phone gets, and what
anyone gets who navigates there directly or follows a link.

## The four views

Day, Week, Month and Agenda. A view is part of the URL — `/calendar/week/2026-08-05` — rather than
something the browser remembers, which means every view of every date is bookmarkable, the browser's
back button does the obvious thing, and the range behind it stays one indexed query.

| View | What it covers |
|---|---|
| **Day** | The one day. |
| **Week** | Monday to Sunday. |
| **Month** | Six weeks, starting on the Monday of the week the 1st falls in — so the days spilling in from the neighbouring months are shown, as a month grid always does. |
| **Agenda** | A rolling list of the next 30 days, skipping the empty time between entries. |

The toolbar above the grid holds **Previous**, **Today** and **Next**, the date or range you are
looking at, the view switcher, and a **New event** button. Previous and Next step by a month in
month view, by seven days in week view, and by a day everywhere else — agenda included, because it
is a rolling list rather than a page.

The full page opens on **Week**. The docked pane opens on **Agenda** — it is 380 pixels of a shared
row, and a month grid in that width is a lot of empty cells. The pane still offers all four views,
as icons rather than words, because the version that dropped the switcher entirely left the pane
able to show nothing but its agenda.

In month view a day that holds more entries than fit says **N more**, which opens that day.

### The time grid

Day and Week are drawn as a time grid: hours down the left, and every event drawn where it actually
starts and as long as it actually lasts. Overlapping events are placed in lanes, so three things
happening at once are three narrower blocks side by side, and the lane count holds steady across an
unbroken run of overlaps rather than changing block by block.

**The docked pane draws the same grid the page does.** It used to keep an older column list instead,
on the reasoning that seven positioned columns need roughly a screen's width — true of 380 pixels,
and no longer the right conclusion, because the pane's width is now yours: drag its handle as wide
as you like, and past the end of its range it becomes the calendar full-width. A pane that quietly
drew a *different* calendar was the worse answer, since widening it to see the timeline gave you the
list anyway.

A seven-day grid is still tight in a narrow pane, and the answer to that is the toolbar: **Day** is
one click away and is a single full-width column.

Month and Agenda have no time axis in either shape. A month cell is a couple of square centimetres
and has no room to say where in the day anything is; an agenda's whole value is that it skips the
empty time, which is exactly the axis a grid would draw.

Three things about the grid are worth knowing:

- **The all-day band is always there**, even on a week with nothing in it, so the grid does not jump
  by a row as you page through. It is deliberately not a drop target: dragging a timed event into it
  would mean "make this all-day", which is a change of kind rather than of time, and kinds are
  changed in the editor.
- **It opens scrolled to the working day** rather than to midnight, which costs one scroll to reach
  the night instead of one scroll to reach everything.
- **Double-clicking empty space creates an event there**, starting at the quarter hour under the
  pointer. A *single* click deliberately does not, and that is the whole reason it is a double: a
  single click on the background has to be told apart from the end of a drag on every release, and
  getting that wrong opens a dialog every time you finish moving a meeting. Each day heading keeps
  its own **+** button, which is the same thing for a keyboard — a double-click is not a gesture a
  keyboard has.

A seven-day week is tight below about 640 pixels — each column ends up around fifty pixels and a
block says its colour and little else. The paths a phone actually takes into the calendar avoid it:
the pane, which is what opens from the mail, keeps the agenda, and Day view is one tap away in the
toolbar and is a single full-width column.

## Creating and editing an event

**New event** in the toolbar, the **+** on a day heading, or clicking an existing event, all open the
same editor. It is a modal over whatever page you were on — the calendar page, or a mail view with
the calendar docked beside it — and saving puts you back there rather than dragging you to the
calendar.

The fields are **Title**, **Starts**, **Ends**, **All day**, **Calendars**, **Repeat**, **Location**,
**Description** and **Reminders**, with **Apply to** appearing when the event repeats. An event
saved with an empty title is called *Untitled* rather than refused. An end before the start is
refused: *Those times do not work — the end has to come after the start.*

**Delete** sits beside **Save** in the same form. On an event plMail read out of a message there is
also **Not an event**, which is a different thing from deleting — see
[Invitations and events from mail](calendar-invitations.md). **Download .ics** takes the event out as
a calendar file.

### Repeating events

**Repeat** offers *Does not repeat*, *Every day*, *Every week*, *Every month* and *Every year*. More
elaborate rules — every second Tuesday, weekdays only — are not written in plMail, but they are
carried faithfully when they arrive from somewhere that can write them: an invitation, an imported
`.ics`, or a connected calendar. Saving such an event from the editor with the dropdown left alone
does not flatten it.

A repeating event is stored once, as a rule, and the individual occurrences are drawn from it. They
are written out into a bounded window around today — a year back and two years forward — with a hard
ceiling of 1000 occurrences per event, so a rule that says "every second, forever" is finite rather
than fatal. The expansion happens in the event's own time zone, so a 09:00 Berlin standup is at
09:00 Berlin in November as well as in July.

### This occurrence, or all of them

When you open an editor from an occurrence of a repeating event, the editor is opened *on that
occurrence*: the times shown are the ones that occurrence actually has, including any move it has
already had. **Apply to** then offers two answers.

**This event** changes only the occurrence you opened. Nothing happens to the series or to any other
instance — the change is filed against the series as a patch, keyed by where the rule originally put
that instance, so editing the same occurrence twice updates the patch rather than stacking a second
one beside it.

**All events** changes the series. This is the one that surprises people, so it is worth stating what
it does to the times: because the editor was showing *that occurrence's* times, the fields are read
as the change you made to that occurrence, and the same shift and the same new duration are applied
to the series. Renaming a weekly meeting from its fifth occurrence therefore renames the series and
leaves it starting where it always started; pushing that fifth occurrence half an hour later and
choosing **All events** moves every occurrence half an hour later. The alternative — reading the
fields as the series' new absolute times — would drag the whole series onto the day you happened to
be looking at.

Two limits follow from how a per-occurrence edit is stored:

- **Reminders belong to the whole event.** The editor says so where you would otherwise expect the
  scope radio to apply: a save scoped to *This event* writes the times and the title for that
  occurrence and leaves the reminders exactly as they were on the series.
- **One occurrence cannot be added to a calendar it is not on.** Ticking a new calendar under
  *This event* is refused whole, with *One occurrence cannot be added to a calendar on its own.
  Choose "All events" to put this meeting on another calendar.* There is no row for a single
  occurrence to create, so honouring it would mean inventing a series out of that one occurrence's
  times.

Deleting works the same way. **All events** removes the series; **This event** takes one occurrence
off it, leaving the series and every other instance untouched.

## Dragging and resizing

On the time grid, an event block can be dragged to another time or another day, and resized by its
bottom edge. Changes snap to fifteen minutes. Nothing is written while you drag — the preview is
drawn in the browser and thrown away, and the change is submitted as an ordinary form when you let
go, so the grid you end up looking at is the one the server drew. A request that fails cannot leave
a block sitting somewhere the database disagrees with.

The keyboard does the same work: focus a block, then hold **Alt** and use the arrow keys to move it,
**Alt+Shift** to change how long it lasts, **Enter** to save and **Escape** to put it back. Every
change is announced, so a screen reader hears where the block went.

A repeating event asks the same this-one-or-all question a drag would otherwise answer silently, in a
dialog, before anything is submitted. An event on a calendar that does not accept changes cannot be
dragged at all, and says so.

## One event on several calendars

The **Calendars** control in the editor is a checkbox per calendar you own, ticked wherever this
meeting already is. The list is every calendar, not only the ones the meeting is on, so it says two
different things at once:

- **Tick a calendar it is not on**, and a copy is written there when you save.
- **Untick one it is on**, and that copy is left alone — not deleted. The editor spells this out:
  *"untick one to leave that copy as it is — it will then differ from the others and show up as its
  own entry."*

That second one is the trap, and the reason is worth understanding. Every copy of a meeting carries
the same UID, because a UID identifies the meeting rather than the row: that is what lets an update
from the organiser, a re-imported `.ics` and a provider's own copy all find every copy of the
meeting. The calendar merges copies into a single entry only *while they agree* about the five
things you can see on it — start, end, title, all-day, and whether it has been called off. Leave one
copy behind at the old time and it no longer agrees, so it splits out and is drawn as its own entry
on its own day. That is deliberate: a merged entry that quietly picked a winner would hide a real
disagreement behind a tidier screen.

A merged entry shows the colours of every calendar it covers and says *On Work, Personal* in its
tooltip. Clicking it opens one editor, with those calendars already ticked.

A calendar that does not accept changes — a mirror of a published feed, or a shared calendar you can
only read — appears in the list, marked *This calendar does not accept changes*, and cannot be
ticked. Unticking everything is refused: *Nothing was chosen, so nothing was changed. Tick at least
one calendar.*

**Delete reads the same ticks.** The copies on ticked calendars go and the rest stay exactly where
they are, read-only ones included. A ticked calendar the meeting was never on has nothing to delete
and is simply skipped.

Dragging a merged event has no checkboxes to offer, so it means what the editor means by default:
every writable copy moves.

## Managing calendars

**Settings → Calendars** is where calendars are created, renamed, recoloured, given a time zone,
hidden from the views, and nominated as the one new events land on. A calendar's time zone is the
one it is read in, and the one an event carrying no zone of its own is shown in.

plMail creates two kinds of calendar for you: one default calendar per user, and one per mail
account, which is where events found in that account's mail are filed. Neither can be deleted —
deleting one only means it is created again, and the events would go in the meantime. Calendars you
made yourself, and mirrors of remote ones, can be deleted; deleting takes every event on the
calendar with it.

Hiding a calendar hides it everywhere, consistently: the views, the topbar's next-thing indicator,
and the *Happening soon* list all read visible calendars only.

## Things that bite

**Unticking a calendar in the editor does not remove the event from it.** It means "leave that copy
alone". The copy stays where it is, stops agreeing with the ones you did change, and appears as a
separate entry from then on. To take a meeting off a calendar, tick that calendar and press
**Delete** — the delete acts on exactly the ticked ones.

**A copy of an event that came from mail is still an event from mail.** Putting a meeting on a
second calendar does not cut either copy off from the booking it was read out of: a later message
moving the meeting moves both. See
[Invitations and events from mail](calendar-invitations.md) for when a change you make yourself
*does* stop that.

**"All events" applies your change as a shift, not as an absolute time.** Opening the fifth
occurrence of a weekly meeting, changing the start to Thursday 14:00 and choosing **All events**
moves the entire series by that difference. If you only meant that one week, choose **This event**.

**A copy created on a second calendar starts as the plain series.** It gets no participants — pushing
an attendee list to a provider is how the provider decides to re-send the invitation to everyone on
it — and none of the per-occurrence moves the original had already accumulated. Those instances stay
where they are on the original and are drawn as their own entries until you move them on both
copies.

**The editor never opens inside the pane.** It is rendered at the top of the page instead, because
both the pane and the mail pane carry a backdrop filter, which makes them clip anything positioned
inside them. This is invisible when it works and completely broken when it does not.

**Reminders are not covered by the scope radio.** Setting one while *This event* is selected sets it
on the whole series, and there is no way to give one occurrence a reminder of its own.

**An occurrence beyond the materialised window does not exist yet.** Occurrences are written out a
year back and two years forward from when the event was last saved. A repeating event that has not
been touched in a long time can therefore run out of drawn occurrences at the far end; saving it
again rewrites them from today.

---

**Related:** [Invitations and events from mail](calendar-invitations.md) ·
[Reminders](calendar-alerts.md) · [Connected calendars](calendar-sync.md) ·
[Sharing and booking](calendar-sharing.md)

**How it works:** [The calendar model](../internals/calendar-model.md) —
the JSCalendar object behind an event, how occurrences are materialised, and how overrides are stored.
