**Removing a large account no longer fails with "something went wrong" (#672).** Removing an
account deleted all of its mail inside the page request, and on a big mailbox — a Gmail account of
a few tens of thousands of messages — that took longer than the thirty seconds a request may last,
so the removal was undone and only a misleading Gmail warning was left in the log. Removing now
runs in the background: the account stops syncing and sending at once, its row stays greyed out
with how many messages are gone so far, the same progress shows under Background work in the top
bar, and the row disappears when the account is gone. Deleting many messages at once, such as
emptying the bin, is also faster: the database now updates each conversation's bin state once per
deletion rather than once per message. The migration only replaces a trigger and touches no rows.

**Importing contacts or a calendar file no longer fails on a large file (#672).** Both imports ran
inside the page request; a thousand contacts took over forty seconds, past the thirty a request may
last, so a modest address book failed with "something went wrong" and nothing was imported. Both now
run in the background after the file has been checked, with progress under Background work in the
top bar and a message with the result when they are done. The contacts import is also much faster,
because it no longer slows down with every contact already imported. After a contacts import you
stay on the contacts list; open Duplicates to merge anything imported twice.

**Deleting an inbox tab of your own answers at once, whatever it held (#672).** The tab goes
straight away, and putting its conversations back into the tabs they belong in runs in the
background instead of inside the request, where a tab of tens of thousands of conversations could
run out of time.
