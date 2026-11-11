**An empty IMAP inbox no longer logs an import warning every minute.** A folder whose history
import had reached its lowest message asked the server for mail below UID 1. Dovecot refuses that
search, and the refusal was logged as "the folder is not on the server" and asked again every
minute, without end. Such a folder is now marked imported straight away, and the warning, when a
server does refuse the search, says so instead of blaming a missing folder. No schema change.
[#578](https://github.com/karatektus/pl_mail/pull/578).
