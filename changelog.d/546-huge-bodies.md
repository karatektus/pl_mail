**A message with megabytes of text is stored and searchable, and no longer stops the search index
being rebuilt.** Postgres refuses a search index entry over 1 MB, and plMail fed it a message's
whole body. Since v0.4.2 also indexes mail sent as HTML only, a message with several megabytes of
HTML stopped the background rebuild after v0.4.2 at the first 500 messages, and such a message
arriving new could not be stored at all: the sync tried it a few times and then skipped it. Search
now reads the first 200,000 characters of a body (about 30,000 words; the rest of a body that long
is not searchable), and a message the index still cannot take is logged and passed over instead of
holding up the others. A message the sync skipped before this update is not fetched again on its
own. The migration replaces the search function, including one patched by hand; no table is
rewritten.
[#546](https://github.com/karatektus/pl_mail/issues/546).
