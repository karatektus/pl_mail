**Administrators can run backfills from the admin panel.** Admin → Backfills lists every repair
`app:backfill` runs, newest first, because the newest is nearly always the one an update asks for.
Choosing one explains in plain words what it does, when it was added, and whether it is safe to
run twice; many can also count
beforehand how many messages they would look at, and the ones that re-read mail take a window of a
day, a week, four weeks or everything. Run queues it for the maintenance worker, and Recent runs
shows who started what, its output while it goes, and how it ended. On the console, the task list
`app:backfill` offers is now newest first too.
[#726](https://github.com/karatektus/pl_mail/issues/726).

**Migration:** adds the `backfill_run` table. No deployment change.
