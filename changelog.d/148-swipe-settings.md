**On a phone, a swipe to the right or to the left always did the same thing.** The two directions
were read or unread and archive, with no way to change either. Settings → Mail → Swipe actions now
sets each direction to read or unread, archive, star, or nothing, and the defaults are the same as
before. Trash is not one of the choices: a swipe commits without a tap, and a trashed row has no
Undo. A value stored for trash reads as the direction's default. No schema change: the two choices
live in the settings bag, and the config backup carries them.
[#148](https://github.com/karatektus/pl_mail/issues/148), by @Flouss.
