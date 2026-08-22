<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageThreadRepository;

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
        private MessageThreadRepository $threads,
        private LabelRepository         $labels,
    ) {
    }

    /**
     * @param string $scope one of `inbox`, `archive`, `trash`, `spam`, `sent`,
     *                      `drafts`, `snoozed`, `starred`, `label`
     * @param string $value the category for `inbox`, the label id for `label`,
     *                      ignored otherwise
     *
     * @return list<MessageThread> at most self::LIMIT of them
     */
    public function threadsIn(User $user, string $scope, string $value, bool $unreadOnly): array
    {
        $page  = 1;
        $found = [];

        do {
            $batch = $this->page($user, $scope, $value, $unreadOnly, $page);

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
     * @return list<MessageThread>
     */
    private function page(User $user, string $scope, string $value, bool $unreadOnly, int $page): array
    {
        if ('inbox' === $scope) {
            // The inbox is per category tab, and the tab IS the view: selecting
            // everything while looking at Notifications must not reach into
            // Primary, which is not on screen.
            $category = MessageCategory::tryFrom($value) ?? MessageCategory::Primary;

            return $this->threads->findForUnifiedInbox($user, $category, $page, self::CHUNK, unreadOnly: $unreadOnly);
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

            return $this->threads->findForLabel($label, page: $page, perPage: self::CHUNK, unreadOnly: $unreadOnly);
        }

        // Starred is not a label role — it is a column on the thread — so it
        // has its own repository method rather than a row in the table below.
        // Being asked for it is not exotic: "select every starred mail" is the
        // example the feature was asked for with.
        if ('starred' === $scope) {
            return $this->threads->findForStarred($user, $page, self::CHUNK, unreadOnly: $unreadOnly);
        }

        $role = self::ROLES[$scope] ?? null;

        if (null === $role) {
            return [];
        }

        return $this->threads->findForRole($user, $role, $page, self::CHUNK, unreadOnly: $unreadOnly);
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
}
