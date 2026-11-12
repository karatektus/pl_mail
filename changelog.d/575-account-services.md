<!-- highlight -->
**Use an account for its mail, its calendar or its contacts — or any two of them.** Each account
now has three switches in **Settings → Accounts**, in place of the single on/off toggle, which only
ever stopped the mail. Switching one off stops plMail talking to the server about it and keeps
what is already here: mail stays readable from the account's row in the sidebar, and calendar and
contact edits made meanwhile go out when you switch them back on. When you add a Gmail or Outlook
account you can tick what it is for, and the consent screen only asks for that — a Google account
connected for its calendar is never asked for access to its mail. While mail is off, **Remove
synced mail from plMail** deletes plMail's copy and nothing on the server.
[#575](https://github.com/karatektus/pl_mail/issues/575).

**Migration:** the account table's `is_active` column is renamed `mail_enabled`, and two columns,
`calendar_enabled` and `contacts_enabled`, are added and filled from what the old switch did:
calendars stay on everywhere, contacts follow the old switch. An account that was switched off now
shows in the sidebar again, dimmed, while it still holds mail. No deployment change.
