<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\InboxTab;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Label\Label;
use App\Entity\Mail\MessageThread;
use App\Entity\Mail\UnifiedInbox;
use App\Entity\User\User;
use App\Repository\Mail\MessageThread\MessageThreadBadgeRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Where new mail is sitting — the numbers behind every "something arrived here"
 * dot.
 *
 * New is MessageThread::isNewAt(): the conversation has never had its row put in
 * front of the user AND it arrived inside MessageThread::NEW_WINDOW. Not unread;
 * see the property's own note. The window is why this class now holds a clock
 * reading as well as a set of counts.
 *
 * A sibling of SidebarCounts rather than a set of methods on it, because the
 * two answer different questions with the same shape and merging them would
 * mean one class where "count" sometimes means unread messages and sometimes
 * means unshown conversations. The dots and the badges are also allowed to
 * disagree — that disagreement is the entire feature.
 *
 * Per-request memoised, per LogAlertGlobal: the inbox renders up to five
 * category dots plus a sidebar's worth from these three reads, and a global
 * that queried per call would be a query per dot.
 */
class NewMailMarkers implements ResetInterface
{
    /** @var array<string,int>|null category value => new thread count */
    private ?array $byCategory = null;

    /** @var array<string,list<string>>|null category value => sender names of new threads */
    private ?array $sendersByCategory = null;

    /** @var array<string,int>|null role value => new thread count */
    private ?array $byRole = null;

    /** @var array<int,int>|null label id => new thread count */
    private ?array $byLabel = null;

    private ?int $starred = null;

    /** @var array<int, int>|null account id => new conversations in its Inbox */
    private ?array $byAccount = null;

    /**
     * The accounts of the unified inbox being drawn, for the tab marks; null
     * for every account. See scopeTabsTo().
     *
     * @var list<int>|null
     */
    private ?array $tabAccounts = null;

    /**
     * The instant this request calls "now".
     *
     * Held for the length of one request so the five category dots, the sidebar
     * dots and the row badges all answer against a single clock reading. Two
     * reads a few milliseconds apart could otherwise straddle the 24-hour
     * boundary and put a dot on a category whose only new thread the list below
     * had just declined to badge.
     */
    private ?\DateTimeImmutable $now = null;

    public function __construct(
        private readonly MessageThreadBadgeRepository $messageThreadBadgeRepository,
        private readonly Security                $security,
        private readonly UnifiedInboxes          $inboxes
    ) {
    }

    /**
     * Worker-mode hygiene — the same rule, and the same reason, as
     * LogAlertGlobal::reset(). FrankenPHP keeps this service alive between
     * requests, so without this the first user a worker serves decides where
     * every later user sees a dot.
     *
     * $now is cleared here too, and that line is now the load-bearing one.
     * Newness became time-dependent when it grew a window, so a worker that
     * kept the first request's clock reading would go on answering "new" for
     * mail that aged out hours ago — and unlike the per-user counts, that fault
     * survives even a worker only ever serving one person.
     */
    public function reset(): void
    {
        $this->byCategory        = null;
        $this->sendersByCategory = null;
        $this->byRole            = null;
        $this->byLabel           = null;
        $this->starred           = null;
        $this->byAccount         = null;
        $this->tabAccounts       = null;
        $this->now               = null;
    }

    /**
     * Whether a thread is new, for the templates.
     *
     * The single-row counterpart of the counts below, and deliberately reading
     * the same clock: a row redrawn on its own by a turbo-stream asks this, the
     * dots ask the counts, and both have to be talking about the same instant.
     */
    public function isNew(MessageThread $thread): bool
    {
        return $thread->isNewAt($this->now());
    }

    /**
     * New mail answering something you sent — the louder half of newness.
     *
     * Shares `now()` with isNew() above, so a row cannot be told it is an
     * answer by one call and not new by the next: both read the same instant,
     * memoised for the render.
     */
    public function isAnswer(MessageThread $thread): bool
    {
        return $thread->isAnswerAt($this->now());
    }

    private function now(): \DateTimeImmutable
    {
        return $this->now ??= new \DateTimeImmutable();
    }

    /**
     * New conversations in one tab — a built-in one, or one the reader made.
     */
    /**
     * Count the tab marks within one unified inbox (#265) — the one whose
     * strip is on screen. Each inbox has its own strip, and a dot on its
     * Promotions tab for mail that arrived in another inbox would point at a
     * tab with nothing new in it.
     *
     * Called by whatever draws the strip before it asks, and it forgets what
     * it counted before: the marks of one inbox are not another's.
     */
    public function scopeTabsTo(?UnifiedInbox $inbox): void
    {
        $user = $this->security->getUser();

        $this->tabAccounts       = null === $inbox || false === $user instanceof User ? null : $this->inboxes->scopeOf($user, $inbox);
        $this->byCategory        = null;
        $this->sendersByCategory = null;
    }

    /**
     * New conversations in one unified inbox: the dot beside its row in the
     * sidebar. One grouped read per request answers every inbox.
     */
    public function forInbox(UnifiedInbox $inbox): int
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return 0;
        }

        // Every account in it: the Inbox dot's own number, which the page has
        // read already — no second grouped query for the same answer.
        if (null === $this->inboxes->scopeOf($user, $inbox)) {
            return $this->forRole(LabelRole::Inbox);
        }

        $this->byAccount ??= $this->messageThreadBadgeRepository->countNewInboxPerAccount($user, $this->now());

        $count = 0;

        foreach ($this->inboxes->accountIdsOf($user, $inbox) as $accountId) {
            $count += $this->byAccount[$accountId] ?? 0;
        }

        return $count;
    }

    public static function inboxKey(UnifiedInbox $inbox): string
    {
        return 'new:inbox:' . $inbox->id;
    }

    public function forTab(InboxTab $tab): int
    {
        if (null === $this->byCategory) {
            $user = $this->security->getUser();

            $this->byCategory = null === $user
                ? []
                : $this->messageThreadBadgeRepository->countNewByCategoryForUnifiedInbox($user, $this->now(), $this->tabAccounts);
        }

        return $this->byCategory[$tab->value] ?? 0;
    }

    /**
     * Who the new mail in a tab is from — the sender hint under it.
     *
     * Reads the same clock as forTab() so the names and the number they sit
     * beside describe the same instant; a thread that ages out of the window
     * between two reads would otherwise be counted by one and named by the
     * other.
     *
     * @return list<string> newest arrival first, at most three
     */
    public function sendersForTab(InboxTab $tab): array
    {
        if (null === $this->sendersByCategory) {
            $user = $this->security->getUser();

            $this->sendersByCategory = null === $user
                ? []
                : $this->messageThreadBadgeRepository->newSendersByCategoryForUnifiedInbox($user, $this->now(), accountIds: $this->tabAccounts);
        }

        return $this->sendersByCategory[$tab->value] ?? [];
    }

    /**
     * The dot obeys the same silence the badge does.
     *
     * countNewPerRole() attributes a thread to every role it carries, exactly
     * as countUnreadPerRole() does, so answering a conversation lit a dot on
     * Sent that meant "mail arrived here" about mail that arrived in the Inbox.
     * The badge beside it was the reported half of that (see
     * SidebarCounts::SILENT_ROLES); a dot left behind would be the same wrong
     * statement in the mark that carries no number to argue with.
     *
     * Zeroed rather than not rendered: every marker in the markup has to be
     * findable in the counts payload or the sidebar controller cannot patch it,
     * and BadgeSemanticsTest holds that invariant.
     */
    public function forRole(LabelRole $role): int
    {
        if (false === SidebarCounts::badges($role)) {
            return 0;
        }

        if (null === $this->byRole) {
            $user = $this->security->getUser();

            $this->byRole = null === $user
                ? []
                : $this->messageThreadBadgeRepository->countNewPerRole($user, $this->now());
        }

        return $this->byRole[$role->value] ?? 0;
    }

    public function forLabel(Label $label): int
    {
        if (null === $this->byLabel) {
            $user = $this->security->getUser();

            $this->byLabel = null === $user
                ? []
                : $this->messageThreadBadgeRepository->countNewPerUserLabel($user, $this->now());
        }

        return $this->byLabel[(int) $label->id] ?? 0;
    }

    public function forStarred(): int
    {
        if (null === $this->starred) {
            $user = $this->security->getUser();

            $this->starred = null === $user
                ? 0
                : $this->messageThreadBadgeRepository->countNewForStarred($user, $this->now());
        }

        return $this->starred;
    }

    /**
     * The dot keys, spelled once.
     *
     * The counts endpoint emits them and the templates render `data-count-key`
     * with them, and the sidebar controller matches the two up by string — so
     * the string is built here rather than in both places, which is how
     * "node:" keys once outlived the markup that read them.
     */
    public static function categoryKey(MessageCategory|InboxTab $category): string
    {
        return 'new:category:' . $category->value;
    }

    /**
     * The sender-hint keys, namespaced away from both the "new:" counts and
     * the unread badge keys: their values are STRINGS — joined sender names —
     * and a patcher expecting a number must never be able to reach one.
     */
    public static function categorySendersKey(MessageCategory|InboxTab $category): string
    {
        return 'senders:category:' . $category->value;
    }

    public static function roleKey(LabelRole $role): string
    {
        return 'new:role:' . $role->value;
    }

    public static function labelKey(Label $label): string
    {
        return 'new:label:' . $label->id;
    }

    public const string STARRED_KEY = 'new:starred';
}
