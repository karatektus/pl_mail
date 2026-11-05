**Mail sent as HTML only is now found by the words in it, and summarised from them.** Messages
without a plain-text part, such as most mail from shops and newsletters, were searchable only by
subject and sender. Thread summaries said there was "no message content", and semantic search and
the mail sorter saw the headers alone. Search, the JMAP `text` and `body` filters, search snippets,
embeddings, summaries, the sorter and the writing helper now read the words of the HTML body.
**Migration:** quick: one column on `message`, and the search index is emptied and rebuilt in the
background, newest mail first, a few thousand messages a minute. Until the rebuild reaches a
message, full-text search does not find it; only the slower substring match does, when a search
finds little else. New mail is indexed as it arrives. `php bin/console app:backfill html-body-text`
finishes the rebuild at once. Messages sent as HTML only get their embeddings made again by the
next index pass, and summaries made before are shown as out of date.
[#477](https://github.com/karatektus/pl_mail/issues/477), reported by @pauljhill.
