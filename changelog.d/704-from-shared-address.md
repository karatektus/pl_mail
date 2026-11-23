**Compose no longer sends from the wrong account when two accounts share an address.** When two
accounts could both send as one address under the same sender name, as Gmail's "Send mail as"
often sets up, their From options read the same and one replaced the other. The window then opened
with an empty From, and sending used the other account without saying so. Both options are now
kept, and options that would read the same name the account they send through ("· via …"). A
window whose From is not among the options no longer falls back to the first one: it asks for an
address and refuses to send until one is picked. No schema change.
[#704](https://github.com/karatektus/pl_mail/issues/704), reported by @wbilger.
