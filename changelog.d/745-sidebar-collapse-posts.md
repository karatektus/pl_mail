**A tab no longer floods the server after its session has quietly renewed.** Every live redraw of
the sidebar (after a label change, in any tab) saved the open or collapsed state of each section and
tree again, twice over for the two sidebar copies, although nobody had clicked anything; a click
saved it twice. When the tab's form
token had gone stale (the session ended and "keep me logged in" signed the browser back in), each
of those saves was refused by sending it to the login page, and the browser followed that to a full
inbox render: hundreds a minute from one tab, slowing the mailbox for everyone. The sidebar now
saves only a state somebody changed, a stale token is answered with a plain refusal instead of a
redirect to the login page, and fetching the label lists no longer redraws them in every other tab.
No schema change. [#745](https://github.com/karatektus/pl_mail/issues/745), reported by @wbilger.
