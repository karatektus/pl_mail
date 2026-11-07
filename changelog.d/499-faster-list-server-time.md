**Opening a category tab or folder answers faster.** Most of the time the server spent on each
click went into three things it redid every time. The preview line under each subject was cut out
of the message's full HTML again on every visit, which for a newsletter of 30–100 KB is the most
expensive part of drawing a row. It is now made once, the first time the row is listed, and kept.
The tab totals, the unread tabs and the page count were three separate passes over the whole inbox
and are now one. And the 42 KB script map at the top of every page is now built once per server
process instead of on every request. On a test mailbox with newsletter-sized mail the list went from
about 150 ms to about 50 ms on a laptop; on slower hardware the saving grows with it. Responses to
a signed-in user now also carry a `Server-Timing` header, which the browser's developer tools show
as database and total server time.
[#499](https://github.com/karatektus/pl_mail/issues/499).
