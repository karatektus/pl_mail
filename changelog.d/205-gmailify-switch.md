**A Gmail account can stop filing mail under your other accounts.** A Gmail account that collects
mail for another connected address files that mail under the other account (Gmailify), and
nothing in Settings could turn this off. After a domain moved from Gmail to an IMAP provider of
its own, Gmail went on matching old and late copies to it. Settings → Aliases now has a switch
under each Gmail account, on by default. Turned off, the Gmail account keeps only mail addressed
to itself, and the other account's own IMAP copies lose their link to Gmail, in a background job
on the maintenance queue. Mail Gmail filed there that the IMAP server never had keeps it. No
schema change: the choice lives in the account's settings, as it did before there was a switch.
[#205](https://github.com/karatektus/pl_mail/issues/205).
