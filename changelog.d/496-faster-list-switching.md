**Switching category tabs and folders is faster.** The mail list used to block the page for about
100 ms on every switch (around 400 ms on a slower phone) while it built the new rows, because each
row carried its own copy of the snooze and spam menus. The list now has one of each, opened for the
row whose button you press, and the rows' entrance animation and the tab strip no longer make the
browser lay the page out several times over. Measured on a 50,000-message mailbox: about 50 ms on a
fast machine and 155 ms on a slow one. The menus look and work as before; the snooze menu can now be
walked with the arrow keys, and its times are worked out when you open it.
[#496](https://github.com/karatektus/pl_mail/issues/496).
