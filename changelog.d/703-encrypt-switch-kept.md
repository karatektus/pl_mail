**Switching Encrypt or Sign off in a draft stays off when the draft is opened again.** A draft that
was going to be encrypted when it was saved, such as mail to one of your own addresses, is kept
encrypted on the server. Opened again from Drafts, it came back with Encrypt switched on, whatever
had been chosen since, and went out encrypted with "..." as its subject. The Encrypt and Sign
choices are now kept inside the encrypted draft and come back with it, and flipping either one
saves the draft. No schema change. [#703](https://github.com/karatektus/pl_mail/issues/703),
reported by @wbilger.
