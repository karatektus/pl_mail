<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Label\Label;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\MessageThreadRepository;
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
        private readonly MessageThreadRepository $threadRepository,
        private readonly Security                $security,
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

    public function forCategory(MessageCategory $category): int
    {
        if (null === $this->byCategory) {
            $user = $this->security->getUser();

            $this->byCategory = null === $user
                ? []
                : $this->threadRepository->countNewByCategoryForUnifiedInbox($user, $this->now());
        }

        return $this->byCategory[$category->value] ?? 0;
    }

    /**
     * Who the new mail in a category is from — the sender hint on its tab.
     *
     * Reads the same clock as forCategory() so the names and the number they
     * sit beside describe the same instant; a thread that ages out of the
     * window between two reads would otherwise be counted by one and named by
     * the other.
     *
     * @return list<string> newest arrival first, at most three
     */
    public function sendersForCategory(MessageCategory $category): array
    {
        if (null === $this->sendersByCategory) {
            $user = $this->security->getUser();

            $this->sendersByCategory = null === $user
                ? []
                : $this->threadRepository->newSendersByCategoryForUnifiedInbox($user, $this->now());
        }

        return $this->sendersByCategory[$category->value] ?? [];
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
                : $this->threadRepository->countNewPerRole($user, $this->now());
        }

        return $this->byRole[$role->value] ?? 0;
    }

    public function forLabel(Label $label): int
    {
        if (null === $this->byLabel) {
            $user = $this->security->getUser();

            $this->byLabel = null === $user
                ? []
                : $this->threadRepository->countNewPerUserLabel($user, $this->now());
        }

        return $this->byLabel[(int) $label->id] ?? 0;
    }

    public function forStarred(): int
    {
        if (null === $this->starred) {
            $user = $this->security->getUser();

            $this->starred = null === $user
                ? 0
                : $this->threadRepository->countNewForStarred($user, $this->now());
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
    public static function categoryKey(MessageCategory $category): string
    {
        return 'new:category:' . $category->value;
    }

    /**
     * The sender-hint keys, namespaced away from both the "new:" counts and
     * the unread badge keys: their values are STRINGS — joined sender names —
     * and a patcher expecting a number must never be able to reach one.
     */
    public static function categorySendersKey(MessageCategory $category): string
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
