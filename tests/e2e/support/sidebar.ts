/**
 * The sidebar's Inbox row, as specs should find it.
 *
 * Since #265 the Inbox slot holds the reader's unified inboxes, each a row of
 * its own: the link is `/mail/inbox/<id>`, the badge counts `inbox:<id>` and
 * the new-mail dot `new:inbox:<id>`. The e2e users have the starting rows
 * (one Inbox over every account, and an empty Other that is not drawn), so
 * there is exactly one such row, and it is the first one.
 *
 * The first inbox is also what the bare `/mail/inbox` shows, and its link
 * says so with `data-also-open-at`. Matching on that, rather than on an id
 * that differs per seed user, keeps the selectors free of fixture state.
 * The old `a[href="/mail/inbox"]` and `role:inbox` no longer match anything,
 * which is how 70 tests broke at once on #483.
 */

/** The first unified inbox's link — what "Inbox" in the sidebar is. */
export const INBOX_LINK = '#sidebar a[data-also-open-at="/mail/inbox"]';

/** That inbox's unread badge. */
export const INBOX_BADGE = '#sidebar [data-count-key^="inbox:"]';

/** That inbox's "new mail" dot. */
export const INBOX_NEW_DOT = '#sidebar [data-new-dot][data-count-key^="new:inbox:"]';
