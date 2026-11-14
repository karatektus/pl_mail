<!-- highlight -->
**An account can only send, or only receive (#628).** An SMTP relay such as SMTP2go has no mailbox
to read, and adding one meant inventing an incoming server that then failed every sync. Now the
incoming server can be left blank: the account sends, is offered in **From**, keeps what you send
under Sent in plMail, and never tries to sync. A blank SMTP server, as before, is an account that
only receives — and it is no longer offered in **From**, where every send from it failed. Receiving
and sending are two switches of their own in an account's settings, beside calendar and contacts,
and Gmail and Outlook can be connected for either.

**Migration:** the account table gains `send_enabled`. It is switched on wherever mail was, except on
a password account with no SMTP server that does not use JMAP — those were offered as senders and
every send from them failed, so they now start out as receive-only.
