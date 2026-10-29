**A contact or calendar provider rate-limiting plMail no longer fills the log with errors.** When
Google or Microsoft refused a contact or calendar sync for a minute, plMail logged it as an error,
then as a warning with two stack traces for every retry, and as a critical failure if the limit
was still there after the last one. Such a job now waits for the limit to clear and tries again,
and the log says so once, at info level, with the reason Google gives. Saving several Google
contacts at once also uses about half as many requests, which makes hitting the limit less likely.
[#435](https://github.com/karatektus/pl_mail/issues/435).
