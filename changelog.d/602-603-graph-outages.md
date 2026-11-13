**A Microsoft outage no longer leaves Outlook sync jobs to be put back by hand.** Graph answering
500, 502 or 504 is Microsoft's side failing, and is now treated like its throttling: a sync still
failing on its last attempt is dropped with one warning, since the next scheduled sync resumes
from the same point, and a batch of messages is put back to wait instead of being parked on the
failed queue at CRITICAL. [#603](https://github.com/karatektus/pl_mail/issues/603).

**The "keeps failing to sync" card gives advice that fits the account.** It told every account to
check the host, the port and the password, including Outlook and Gmail accounts, which have none of
the three. Those now get their own text, and when the provider's servers are the ones failing the
card says so and that there is nothing to change. [#602](https://github.com/karatektus/pl_mail/issues/602).
