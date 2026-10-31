**File in category works on IMAP accounts.** The sender check behind it failed every message read
over IMAP, so a filter filing into a tab of your own matched its mail, said it had been applied,
and filed none of it. php-imap returns the receiving server's name alone beside the
Authentication-Results line, and that bare name was read as a second, failing verdict. Mail already
synced is fixed too: press **Apply to existing mail** on the filter. No schema change.
[#381](https://github.com/karatektus/pl_mail/issues/381), by @jo2904.
