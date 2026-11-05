**Mail sent as HTML only is now found by the words in it, and summarised from them.** Messages
without a plain-text part, such as most mail from shops and newsletters, were searchable only by
subject and sender. Thread summaries said there was "no message content", and semantic search and
the mail sorter saw the headers alone. Search, the JMAP `text` and `body` filters, search snippets,
embeddings, summaries, the sorter and the writing helper now read the words of the HTML body.
**Migration:** one column on `message`, and `search_vector` is rebuilt, which rewrites the table
and takes a while on a large mailbox. Mail already stored is fixed by
`php bin/console app:backfill html-body-text`; it drops those messages' embeddings, so the next
index pass makes them again from the body. Summaries made before are shown as out of date.
[#477](https://github.com/karatektus/pl_mail/issues/477), reported by @pauljhill.
