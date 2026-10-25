<!-- highlight -->
**POP3 accounts.** For a provider that serves POP3 and no IMAP, Settings → Mail accounts → Add
account now has an **Incoming protocol** choice. A POP3 account is one inbox: reading, starring,
labelling and moving stay in plMail, mail you send is labelled Sent here, and new mail is fetched
with the regular sweep. Each message is recognised by its UIDL, so nothing is fetched twice, and a
large mailbox comes in newest first. Mail stays on the server unless you choose **Delete it from
the server**, which deletes a message there only once it is stored in plMail. An existing account
can be switched between IMAP and POP3 and keeps its mail; the next sync recognises what is
already here by its Message-ID, or by sender, subject and date when it has none. Schema change: `account.incoming_protocol` and a `pop3_uidl`
table. [#289](https://github.com/karatektus/pl_mail/issues/289), by @mmssix.

**A connected calendar with a very long event address syncs again.** Some CalDAV servers name an
event with an address longer than 255 characters — an event imported from Google into Baïkal is
one — and that one event failed the whole calendar's sync. The column holding it is now unbounded.
Schema change: `calendar_event.remote_id` becomes `TEXT`. By @mmssix.
