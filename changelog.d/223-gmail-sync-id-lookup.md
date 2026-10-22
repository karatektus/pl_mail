**A Gmail sync no longer reads every Gmail id you have stored.** To tell new mail from mail it
already had, each Gmail sync loaded the id of every message stored for you, and every one it had
declined. It did this after every history window, including the many that bring nothing, and on
every page of a listing. On a large mailbox that is thousands of rows every few minutes, and more as
the mailbox grows. It now asks only about the ids Gmail has just listed, and nothing when there are
none. No schema change. [#223](https://github.com/karatektus/pl_mail/issues/223), reported by
@phdelodder.
