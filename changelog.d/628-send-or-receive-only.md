<!-- highlight -->
**An account can only send, or only receive (#628).** An SMTP relay such as SMTP2go has no mailbox
to read, and adding one meant inventing an incoming server that then failed every sync. The account
form now has a **Receive mail** switch on its Incoming heading and a **Send mail** switch on its
Outgoing one; switch one off and its fields fold away. A send-only account is offered in **From**,
keeps what you send under Sent in plMail, and never tries to sync; a receive-only one syncs and is
not offered as a sender. The same two switches are in an account's settings, beside calendar and
contacts, and Gmail and Outlook can be connected for either.

**Migration:** the account table gains `send_enabled`. It is switched on wherever mail was, except on
a password account with no SMTP server that does not use JMAP — those were offered as senders and
every send from them failed, so they now start out as receive-only.
