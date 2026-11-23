**"Select all" on search results acts on the results.** Searching, taking **Select all N** and
pressing the bin (or archive, mark read, label) showed "Started moving to bin — this runs in the
background" and then did nothing: the search page did not tell the server which list it was, so
the background job looked for the conversations of no list, found none, and finished without an
error. It now carries the search, and the job acts on every conversation the search's words found;
ones listed only for a similar meaning are left out. A list the server cannot act on as a whole,
such as one account's own inbox, no longer offers **Select all N**, and a request for one is
refused instead of finishing as a job that did nothing. No schema change.
[#711](https://github.com/karatektus/pl_mail/issues/711), reported by @qntxdev, cause found by
@androidwannabe.
