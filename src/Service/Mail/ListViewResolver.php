<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\InboxTab;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Label\Label\LabelRepository;
use App\Repository\Mail\Message\MessageListRepository;
use App\Repository\Mail\MessageThread\MessageThreadRepository;
use App\Repository\Mail\MessageThread\MessageThreadSearchRepository;
use App\Service\Search\SearchQueryParser;

/**
 * Every conversation in a list, not just the page somebody can see.
 *
 * "Select all" used to mean "select the fifty rows rendered", which is the only
 * thing a checkbox over a paginated list can mean on its own — and it is not
 * what anybody wants it to mean in a bin holding a hundred and ninety-five
 * messages. This is the other half: given the view a person is looking at, the
 * ids of everything in it.
 *
 * ## A descriptor, not a URL
 *
 * The obvious shape is to post the current URL and let the server re-run
 * whatever the list ran. That was rejected: it makes every mail list route an
 * input to a bulk write, so a route added later for reading becomes a way of
 * deleting without anyone deciding that. The client names a scope and a value
 * from a closed set instead, and anything unrecognised resolves to nothing.
 *
 * ## Unread-only is part of the view
 *
 * The list has a filter, and a "select all" that ignored it would act on mail
 * the person cannot see — the exact failure that makes bulk actions frightening.
 * It is carried through to the same repository methods the list itself uses, so
 * the set can only ever be what was on screen, continued past the page break.
 *
 * ## A search is a view too
 *
 * Its value is the query as typed, re-parsed when the job runs, and it is the
 * one scope with no counterpart in `{% block list_scope %}`: the search page
 * keeps `*` there, because that block is also the sync scope and a search has
 * to refresh when any mailbox does. The toolbar names it through
 * `list_view_scope` instead. It used to name nothing, so "select all 1,077,
 * move to bin" posted `*`, resolved to no conversations, and finished as a job
 * that had done nothing without saying so (#711).
 */
final readonly class ListViewResolver
{
    /**
     * The page size used when draining a view.
     *
     * Large, because the whole point is to not stop at a page — but bounded,
     * because this walks into a query that can return a mailbox. Draining in
     * pages keeps the hydration flat rather than loading a hundred thousand
     * rows into one array.
     */
    private const int CHUNK = 500;

    /**
     * A ceiling, and a deliberate one.
     *
     * A user with a quarter of a million messages who selects everything and
     * presses Delete should get a refusal rather than a request that runs until
     * something times out and leaves the mailbox half-changed. The number is
     * far above any real selection and far below anything that hurts.
     */
    public const int LIMIT = 10_000;

    public function __construct(
        private MessageThreadRepository       $threads,
        private MessageListRepository         $emails,
        private LabelRepository               $labels,
        private InboxTabs                     $tabs,
        private AllMailView                   $allMail,
        private UnifiedInboxes                $inboxes,
        private SearchQueryParser             $parser,
        private MessageThreadSearchRepository $search,
    ) {
    }

    /**
     * Whether a scope is one this resolves at all.
     *
     * For BulkStatusController, which refuses a whole-view action on anything
     * else when it is asked rather than when the worker runs. A scope outside
     * the set resolves to nothing — that is the closed set doing its job — and
     * a job over nothing finishes as Done, which is how "select all" on a
     * search reported success while acting on no mail at all (#711).
     */
    public static function names(string $scope): bool
    {
        return true === AllMailView::names($scope)
            || true === in_array($scope, self::SCOPES, true)
            || true === array_key_exists($scope, self::ROLES);
    }

    /**
     * @param string $scope one of `inbox`, `archive`, `trash`, `spam`, `sent`,
     *                      `drafts`, `snoozed`, `starred`, `unread`, `all_mail`,
     *                      `label`, `search`
     * @param string $value the inbox and tab for `inbox` (UnifiedInboxes::listValue()),
     *                      the label id for `label`, the query for `search`,
     *                      ignored otherwise
     *
     * @return list<MessageThread> at most self::LIMIT of them
     */
    public function threadsIn(User $user, string $scope, string $value, bool $unreadOnly): array
    {
        if (self::SEARCH === $scope) {
            return $this->searchResults($user, $value);
        }

        /** @var list<MessageThread> */
        return $this->drain($this->threads, $user, $scope, $value, $unreadOnly);
    }

    /**
     * The same view one email per row (#615): what "select all" holds in the
     * email list, where the replies of a conversation that the list did not
     * show are not part of the selection.
     *
     * Never a search: its results are conversations whatever the reader's list
     * setting, so a search posts the thread level and `search` falls through
     * page() to nothing here.
     *
     * @return list<Message> at most self::LIMIT of them
     */
    public function emailsIn(User $user, string $scope, string $value, bool $unreadOnly): array
    {
        /** @var list<Message> */
        return $this->drain($this->emails, $user, $scope, $value, $unreadOnly);
    }

    /**
     * Every conversation a search matches, chunk by chunk, up to self::LIMIT.
     *
     * The query is parsed again from what was typed, by the parser the page
     * used, rather than stored parsed: the job then holds one string a person
     * could read, and nothing that has to stay in step with ParsedSearchQuery.
     * A query that parses to nothing selects nothing, as the page shows
     * nothing for one — it does not mean the whole mailbox.
     *
     * Whether the body pass is part of the statement is decided once, before
     * the first chunk; see MessageThreadSearchRepository::needsBodyRescue().
     * The unread filter is not read: the search page has none, and `is:unread`
     * is part of the query.
     *
     * @return list<MessageThread>
     */
    private function searchResults(User $user, string $value): array
    {
        $query = $this->parser->parse(trim($value));

        if (true === $query->isEmpty()) {
            return [];
        }

        $withBodyRescue = $this->search->needsBodyRescue($user, $query);

        $page  = 1;
        $found = [];

        do {
            $batch = $this->search->bulkPage($user, $query, $withBodyRescue, $page, self::CHUNK);

            foreach ($batch as $thread) {
                $found[] = $thread;

                if (count($found) >= self::LIMIT) {
                    return $found;
                }
            }

            ++$page;
        } while (count($batch) === self::CHUNK);

        return $found;
    }

    /**
     * Every row of a view, page by page, at the level $lists lists — the two
     * repositories answer the same methods (App\Service\Mail\MailLists).
     *
     * @return list<MessageThread|Message>
     */
    private function drain(MessageThreadRepository|MessageListRepository $lists, User $user, string $scope, string $value, bool $unreadOnly): array
    {
        $page  = 1;
        $found = [];

        do {
            $batch = $this->page($lists, $user, $scope, $value, $unreadOnly, $page);

            foreach ($batch as $thread) {
                $found[] = $thread;

                if (count($found) >= self::LIMIT) {
                    return $found;
                }
            }

            ++$page;
        } while (count($batch) === self::CHUNK);

        return $found;
    }

    /**
     * @return list<MessageThread|Message>
     */
    private function page(MessageThreadRepository|MessageListRepository $lists, User $user, string $scope, string $value, bool $unreadOnly, int $page): array
    {
        // All Mail is not a label either: nothing wears it, the list is a
        // query. The value narrows it to one account, or to none for the list
        // across every account; one that is not the reader's selects nothing.
        if (true === AllMailView::names($scope)) {
            $account = $this->allMail->account($user, $value);

            return match (true) {
                false === $account => [],
                null === $account  => $lists->findForUnifiedAllMail($user, $page, self::CHUNK, unreadOnly: $unreadOnly),
                default            => $lists->findForAccountAllMail($account, $page, self::CHUNK, unreadOnly: $unreadOnly),
            };
        }

        if ('inbox' === $scope) {
            // The inbox is per category tab, and the tab IS the view: selecting
            // everything while looking at Notifications must not reach into
            // Primary, which is not on screen.
            //
            // Unless there are no tabs, and then the view is the inbox. Decided
            // from the person's own setting and not from the value posted: the
            // value is the client's account of what is on screen, and "select
            // all, delete" has to mean the list the server would have drawn —
            // all of it for somebody without tabs, never Primary alone because
            // the page sent an empty string (#29).
            //
            // The reader's own tabs count: the value is a tab key, and one of
            // theirs resolves to their category's conversations.
            //
            // A key that LOOKS like one of theirs and does not resolve — the
            // category was deleted since the page was drawn, or never was theirs
            // — selects nothing. Falling back to Primary, as a mistyped built-in
            // name does, would point a whole-view archive or trash at up to ten
            // thousand conversations that were never on screen, because the job
            // resolves the view when it RUNS, not when the button was pressed.
            //
            // And the inbox is one of the reader's unified inboxes (#265): the
            // value names it ahead of the tab. One that is not theirs, or is
            // gone, selects nothing, for the same reason as a tab that is gone.
            [$inboxId, $value] = UnifiedInboxes::parseListValue($value);

            $inbox = null === $inboxId ? $this->inboxes->first($user) : $this->inboxes->find($user, $inboxId);

            if (null === $inbox) {
                return [];
            }

            $category = null;

            if (true === $user->categorySorting->tabs) {
                $category = $this->tabs->resolve($user, $value);

                if (null === $category && true === InboxTab::looksCustom($value)) {
                    return [];
                }

                $category ??= InboxTab::builtIn(MessageCategory::Primary);
            }

            return $lists->findForUnifiedInbox(
                $user,
                $category,
                $page,
                self::CHUNK,
                unreadOnly: $unreadOnly,
                accountIds: $this->inboxes->scopeOf($user, $inbox),
            );
        }

        if ('label' === $scope) {
            $label = $this->labels->find((int) $value);

            // Somebody else's label resolves to nothing rather than to an
            // error: this is reached from a bulk write, and the honest answer
            // to "act on a label you do not own" is that there is nothing to
            // act on.
            if (null === $label || $label->usr?->id !== $user->id) {
                return [];
            }

            return $lists->findForLabel($label, page: $page, perPage: self::CHUNK, unreadOnly: $unreadOnly);
        }

        // Starred is not a label role — it is a column on the thread — so it
        // has its own repository method rather than a row in the table below.
        // Being asked for it is not exotic: "select every starred mail" is the
        // example the feature was asked for with.
        if ('starred' === $scope) {
            return $lists->findForStarred($user, $page, self::CHUNK, unreadOnly: $unreadOnly);
        }

        // Not a label query either: nothing carries the Unread label, the view
        // is read off the unread counts. $unreadOnly has nothing left to narrow.
        if ('unread' === $scope) {
            return $lists->findForUnread($user, $page, self::CHUNK);
        }

        $role = self::ROLES[$scope] ?? null;

        if (null === $role) {
            return [];
        }

        return $lists->findForRole($user, $role, $page, self::CHUNK, unreadOnly: $unreadOnly);
    }

    /**
     * The scopes that are a system role, by the name the list template already
     * uses for itself (`{% block list_scope %}`), so the client has nothing new
     * to learn and the two cannot drift.
     */
    private const array ROLES = [
        'archive' => LabelRole::Archive,
        'trash'   => LabelRole::Trash,
        'spam'    => LabelRole::Spam,
        'sent'    => LabelRole::Sent,
        'drafts'  => LabelRole::Drafts,
        'snoozed' => LabelRole::Snoozed,
    ];

    /**
     * The search, by the name the toolbar posts for it. A constant because
     * names() and threadsIn() both read it.
     */
    private const string SEARCH = 'search';

    /**
     * The scopes that are neither a role nor All Mail, each answered by a
     * branch of its own in page() or threadsIn().
     *
     * @var list<string>
     */
    private const array SCOPES = ['inbox', 'label', 'starred', 'unread', self::SEARCH];
}
