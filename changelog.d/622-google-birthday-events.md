**Editing a Google birthday event no longer gets refused.** Google only lets a write change some
fields of the events it makes itself, and refused a birthday edit over the visibility plMail sent.
plMail now keeps the type of those events and sends only what Google accepts: the title and
reminders of a birthday (always as private), the visibility and reminders of an event Gmail made
from a mail, and the ordinary edit without visibility for focus time, out of office and working
location. Events synced before this update pick up their type the next time Google changes them or
the calendar is read again in full. [#622](https://github.com/karatektus/pl_mail/issues/622).

**A dismissed reminder no longer sends the event back to your calendar provider.** Calendar apps
connected over CalDAV save an event again when you dismiss its reminder, and plMail pushed every
such save to Google or Microsoft although nothing had changed. That is what made a Google birthday
fail on the day itself. Saves over CalDAV and JMAP that change nothing are no longer pushed.
[#622](https://github.com/karatektus/pl_mail/issues/622).
