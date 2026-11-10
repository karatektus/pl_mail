<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\InboxTab;
use App\Domain\DTO\Push\NotifyScope;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Label\Label\LabelRepository;
use App\Repository\Mail\Message\MessageRepository;
use DateTimeImmutable;

/**
 * Which notification switches one message falls under, and which switches
 * the settings page offers.
 *
 * THE SAME RULE THE ANDROID APP APPLIES (`notifyScopeKeys()` in
 * core/data/NotificationScopes.kt), written out again here because the server
 * now decides for the browsers and the phone still decides for itself — its
 * pushes also drive its sync, so they cannot be dropped here. Two copies of
 * one rule is the price of that; the scope keys are shared (NotifyScope) so
 * that at least the two can never disagree about what a switch is called.
 *
 *  - Mail in the Inbox falls under the TAB the web shows it in: a tab of the
 *    reader's own when one of their filters filed it there, else its built-in
 *    category, and Primary for a category they switched off, which is where
 *    the web lists it. A reader who wants one list (CategorySorting::$tabs off)
 *    has no tab switches on the page, so all their Inbox mail is Primary's.
 *  - Mail with a label falls under that label, Inbox or not. That is what a
 *    label switch is for: a filter that files mail under Work and skips the
 *    Inbox is exactly what somebody turns Work on for.
 *  - The Inbox label itself counts only where there are no tabs. Where there
 *    are, the tabs ARE the Inbox's switches, and an Inbox switch on top of them
 *    would override every one of them.
 *  - System labels other than the Inbox never count: nobody wants to be
 *    interrupted for what they sent, wrote, archived, trashed or let through to
 *    spam, so the page never offers them and nothing can switch them on.
 *
 * Mail still held for the assistant falls under nothing: its tab is not known.
 * HeldMailReleaser asks again when the hold ends. Neither does mail the user
 * wrote themselves (Sent, Drafts), even in a labelled conversation.
 */
final readonly class NotifyScopes
{
    /**
     * How far back "Only notify for unread mail" looks for the mail a push was
     * about. Long enough to cover a hold for the assistant and a sync that
     * took its time; short enough that last week's unread newsletters, which
     * nobody is about to read, do not keep every notification alive.
     */
    public const int RECENT_MINUTES = 15;

    public function __construct(
        private InboxTabs $tabs,
        private LabelRepository $labels,
        private MessageRepository $messages,
    ) {
    }

    /**
     * The scopes one message falls under, for its owner.
     *
     * $inInbox is passed rather than read off the labels because the pipeline
     * knows better: a message fetched from the Inbox counts as in it unless a
     * rule took it out, even where the sync puts the Inbox label on later. See
     * PostIngestPipeline.
     *
     * @return list<string>
     */
    public function forMessage(Message $message, bool $inInbox): array
    {
        if (true === $message->isCategoryHeld()) {
            return [];
        }

        // Mail somebody wrote — a sent copy a sync brought back, a draft — is
        // never news to them, whatever label its conversation carries.
        foreach ($message->labels as $label) {
            if (LabelRole::Sent === $label->role || LabelRole::Drafts === $label->role) {
                return [];
            }
        }

        $user   = $message->account->usr;
        $tabbed = $user instanceof User && true === $user->categorySorting->tabs;
        $scopes = [];

        if (true === $inInbox) {
            $scopes[] = true === $tabbed ? $this->tabScope($message, $user) : NotifyScope::PRIMARY;
        }

        foreach ($message->labels as $label) {
            if (true === $this->countsAsLabel($label, $tabbed)) {
                $scopes[] = NotifyScope::label((int) $label->id);
            }
        }

        return array_values(array_unique($scopes));
    }

    /**
     * Whether any recent unread mail falls under a switch this user has on —
     * the question "Only notify for unread mail" asks before a browser shows a
     * notification. See WebPushController::inboxState().
     *
     * Recent, because the push was about mail that just arrived: an unread
     * promotion from yesterday says nothing about whether THAT mail has been
     * read on the phone in the meantime. And under a switch that is on, for the
     * same reason: unread mail in a tab this user does not want to hear about
     * would otherwise keep every notification alive.
     */
    public function hasRecentUnread(User $user, ?DateTimeImmutable $now = null): bool
    {
        $since = ($now ?? new DateTimeImmutable())->modify(sprintf('-%d minutes', self::RECENT_MINUTES));

        foreach ($this->messages->findRecentUnreadForUser($user, $since) as $message) {
            if (true === $message->isSetAside()) {
                continue;
            }

            foreach ($this->forMessage($message, $message->isInInbox()) as $scope) {
                if (true === $user->notifiesFor($scope)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The switches the settings page draws, in the order it draws them: the
     * tabs (or the Inbox, for somebody without tabs), then the user's labels.
     *
     * @return array{inbox: list<array{scope: string, tab: ?InboxTab}>, labels: list<Label>}
     */
    public function offeredTo(User $user): array
    {
        $inbox = [];

        if (true === $user->categorySorting->tabs) {
            foreach ($this->tabs->allFor($user) as $tab) {
                $inbox[] = ['scope' => NotifyScope::ofTab($tab->value), 'tab' => $tab];
            }
        } else {
            $inbox[] = ['scope' => NotifyScope::PRIMARY, 'tab' => null];
        }

        $labels = array_values(array_filter(
            $this->labels->findForUserTreeOrdered($user),
            static fn (Label $label): bool => null === $label->role,
        ));

        return ['inbox' => $inbox, 'labels' => $labels];
    }

    private function tabScope(Message $message, User $user): string
    {
        // The filter's tab first, as the web lists it: a message one of the
        // reader's filters filed under "Shipping" is Shipping mail, whatever
        // built-in category it also has.
        if (null !== $message->ruleCategory) {
            return NotifyScope::ofTab($message->ruleCategory->tabKey());
        }

        $category = $message->category ?? MessageCategory::Primary;

        return true === $user->categorySorting->isSwitchedOff($category)
            ? NotifyScope::PRIMARY
            : NotifyScope::category($category);
    }

    private function countsAsLabel(Label $label, bool $tabbed): bool
    {
        if (null === $label->role) {
            return true;
        }

        return LabelRole::Inbox === $label->role && false === $tabbed;
    }
}
