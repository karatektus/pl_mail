**Search answers a common word in a fraction of the time.** A search collects matching mail from
several lookups (the words, word beginnings, the subject and the sender) and used to combine them
in a way that made the database read every message one by one instead of using its search index,
and it scored every match for relevance even when the results are sorted by date. Measured on
200,000 messages, a word in one mail of twelve now takes 0.25 seconds instead of about one, and a
word in nearly every mail 1.3 seconds instead of five. The results are the same; sorting by
relevance still scores them. No schema change, no deployment change.
[#544](https://github.com/karatektus/pl_mail/issues/544).
