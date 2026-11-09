**Bookings from genuine senders reach the calendar on iCloud accounts too.** On an iCloud
account, plMail never believed Apple's sender check, because Apple checks mail under `icloud.com`
names while the account reads it from `me.com`. Even when it did believe the check, the separate
BIMI line Apple adds counted as a failure. So every booking was offered as a card, and every change
to one was held. Apple's check is now read the same way as Gmail's: the sender is genuine when
DMARC passes, or DKIM or SPF passes for the From domain. Two different DMARC or SPF results still
count as a failure. No schema change. See
[Calendar invitations → Only from a sender your mail server vouches for](docs/features/calendar-invitations.md#only-from-a-sender-your-mail-server-vouches-for).
[#462](https://github.com/karatektus/pl_mail/issues/462), reported by @AunePVP.
