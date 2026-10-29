**A reply no longer offers to put the meeting from the mail it quotes on your calendar.** Calendar
proposals read the whole body of a message, including the history quoted under a reply, and only
lines starting with `>` were left out. Gmail, Outlook and Apple Mail quote an HTML mail in a
container instead, and Outlook quotes plain text under a `Von:/Gesendet:` block, so a meeting
arranged in one mail was offered again for every "Thanks!" in the thread — even after the card had
been dismissed. Proposals now read only what the sender wrote in that message: the text is cut where
the quote attribution, a forward or an Outlook header block begins, while an interleaved reply keeps
the answers written between its quotes. An Outlook mail synced through Microsoft Graph is read from
its full body instead of the 255-character preview. Cards already shown are not removed. No schema
change. [#437](https://github.com/karatektus/pl_mail/issues/437)
