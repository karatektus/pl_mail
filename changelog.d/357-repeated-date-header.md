**Mail whose Date header is there more than once no longer trips up moves, flags and repairs.** The
sync has stored such mail since v0.3.7, but three other places still read its header with the old
parser and failed on it: the check behind the repair of a message that showed up in two folders,
which logged "Could not establish whether a UID is still on the server" on every sync and never
made the repair; marking the message read, starring or moving it; and deleting it from the server.
They now read it the way the sync does, and the check only asks the server whether the message is
there. The phone apps can import such a message, which the server used to refuse as "not an RFC
5322 message", and an encrypted one is decrypted like any other. No schema change.
[#357](https://github.com/karatektus/pl_mail/issues/357), reported by @sponger544.
