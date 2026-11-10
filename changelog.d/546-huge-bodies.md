**A message with megabytes of text is stored and searchable, and no longer stops the search index
being rebuilt.** Postgres refuses a search index entry over 1 MB, and plMail fed it a message's
whole body. Since v0.4.2 also indexes mail sent as HTML only, a message with several megabytes of
HTML stopped the background rebuild after v0.4.2 at the first 500 messages, and such a message
arriving new could not be stored at all: the sync tried it a few times and then skipped it. Search
now reads the first 200,000 characters of a body (about 30,000 words; the rest of a body that long
is not searchable), and a message the index still cannot take is logged and passed over instead of
holding up the others. Mail the sync skipped this way before the update is fetched now: after the
update every IMAP folder is looked through once in the background, and what the server holds and
plMail does not is brought in (mail deleted in plMail is left out). `app:mail:fetch-missing` does
the same on demand. The migrations replace the search function, including one patched by hand,
and rewrite no table.
[#546](https://github.com/karatektus/pl_mail/issues/546).
