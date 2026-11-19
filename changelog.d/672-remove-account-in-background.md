**Removing a large account no longer fails with "something went wrong" (#672).** Removing an
account deleted all of its mail inside the page request, and on a big mailbox — a Gmail account of
a few tens of thousands of messages — that took longer than the thirty seconds a request may last,
so the removal was undone and only a misleading Gmail warning was left in the log. Removing now
runs in the background: the account stops syncing and sending at once, its row stays greyed out
with how many messages are gone so far, the same progress shows under Background work in the top
bar, and the row disappears when the account is gone. Deleting many messages at once, such as
emptying the bin, is also faster: the database now updates each conversation's bin state once per
deletion rather than once per message. The migration only replaces a trigger and touches no rows.
