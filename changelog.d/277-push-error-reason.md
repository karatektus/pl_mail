**When notifications can't be turned on, Settings now says why.** Every way that turning on
notifications can fail used to end in the same "Something went wrong. Try again." Now the pane says
which step failed: the browser's background worker, the browser's push service, or the server. It
also gives the browser's error name or the server's HTTP status, and writes the full error to the
browser console. For Brave, where push needs "Use Google services for push messaging" switched on,
the push-service message says so. No schema change.
[#277](https://github.com/karatektus/pl_mail/issues/277).
