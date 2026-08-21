<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Repository\Mail\MessageThreadRepository;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The badge is a link now, so its number is a promise about a list.
 *
 * Clicking an unread badge opens that same view narrowed to `?unread=1`. That
 * turns what used to be decoration into a claim that can be checked: the number
 * on the pill has to be the number of rows the click produces. Anything else is
 * the Trash "188 unread against a list of 193" again, except this time the two
 * are one click apart instead of one glance.
 *
 * So these tests do not assert a literal count against a literal list length in
 * isolation — they assert the two against EACH OTHER, on fixtures built to
 * break the pairing if the count and the filter ever grow apart. The threads
 * deliberately hold several unread messages each, because that is precisely the
 * shape under which a message-summing count and a conversation list disagree,
 * and it is why the counts were changed to count conversations at all.
 */
final class UnreadFilteredListsTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    private function repository(): MessageThreadRepository
    {
        return static::getContainer()->get(MessageThreadRepository::class);
    }

    /**
     * The inbox badge against the list its own link opens.
     *
     * Six unread messages over three conversations, plus a read one that must
     * not appear. The badge says three because three rows come back; a sum
     * would say six and open a list of three.
     */
    public function testTheInboxBadgeIsTheNumberOfRowsItOpens(): void
    {
        $this->thread('Two unread in here', unread: 2);
        $this->thread('Three unread in here', unread: 3);
        $this->thread('One unread', unread: 1);
        $this->thread('Nothing unread', unread: 0);

        $repository = $this->repository();
        $badge      = $repository->countUnreadPerRole($this->user)[LabelRole::Inbox->value] ?? 0;

        $rows = $repository->findForUnifiedInbox(
            $this->user,
            MessageCategory::Primary,
            unreadOnly: true,
        );

        self::assertSame(3, $badge, 'the badge counts conversations, not unread messages');
        self::assertCount($badge, $rows, 'the badge promised more or fewer rows than the link delivers');

        // And the filter is doing the work rather than the fixture being small:
        // unfiltered, the same view holds the read conversation too.
        self::assertCount(4, $repository->findForUnifiedInbox($this->user, MessageCategory::Primary));
    }

    /** The same pairing for a custom label. */
    public function testALabelBadgeIsTheNumberOfRowsItOpens(): void
    {
        $work = $this->seedLabel('Work');

        foreach ([['Long thread', 4], ['Short thread', 1], ['Read thread', 0]] as [$subject, $unread]) {
            $thread = $this->thread($subject, unread: $unread);
            $thread->addLabel($work);
        }

        $this->em->flush();

        $repository = $this->repository();
        $badge      = $repository->countUnreadPerUserLabel($this->user)[(int) $work->id] ?? 0;

        $rows = $repository->findForLabel($work, unreadOnly: true);

        self::assertSame(2, $badge);
        self::assertCount($badge, $rows);
        self::assertSame($badge, $repository->countForLabel($work, unreadOnly: true));
    }

    /**
     * Starred, which already counted conversations before any of this — so
     * this is the case that would have quietly kept working and is worth
     * pinning anyway, because the filter is new even where the count was not.
     */
    public function testTheStarredBadgeIsTheNumberOfRowsItOpens(): void
    {
        $starred = $this->thread('Starred and unread', unread: 3);
        $starred->starredAt = new \DateTimeImmutable();

        $readStar = $this->thread('Starred and read', unread: 0);
        $readStar->starredAt = new \DateTimeImmutable();

        $this->thread('Unread but not starred', unread: 2);

        $this->em->flush();

        $repository = $this->repository();
        $badge      = $repository->countUnreadForStarred($this->user);

        self::assertSame(1, $badge);
        self::assertCount($badge, $repository->findForStarred($this->user, unreadOnly: true));
    }

    /**
     * A trashed conversation is absent from both sides at once.
     *
     * The count already excluded it; the filtered list has to as well, or the
     * badge would open a view holding mail it did not count — the same
     * disagreement seen from the other direction.
     */
    public function testATrashedConversationLeavesBothTheCountAndTheList(): void
    {
        $work  = $this->seedLabel('Work');
        $trash = $this->seedLabel('Trash', LabelRole::Trash);

        $thread = $this->thread('Binned but unread', unread: 2);
        $thread->addLabel($work);
        $this->em->flush();

        $repository = $this->repository();

        self::assertSame(1, $repository->countUnreadPerUserLabel($this->user)[(int) $work->id] ?? 0);
        self::assertCount(1, $repository->findForLabel($work, unreadOnly: true));

        $thread->addLabel($trash);
        $this->em->flush();

        self::assertSame(0, $repository->countUnreadPerUserLabel($this->user)[(int) $work->id] ?? 0);
        self::assertCount(0, $repository->findForLabel($work, unreadOnly: true));
    }

    /**
     * The account-scoped folder badge, which is the case this whole approach
     * was chosen for.
     *
     * A label is user-scoped and can be bound to several accounts, so the
     * sidebar's own label row means "everywhere" while the row under an
     * account means "here". forLabelInAccount() exists precisely so the badge
     * does not promise more than the list beside it shows — and the link it
     * now carries has to keep that promise, which means carrying the account
     * as well as the filter. Search could not have expressed this: it has no
     * account: operator, which is why the filter is a parameter on the view.
     */
    public function testAnAccountScopedLabelBadgeMatchesItsOwnAccountsRows(): void
    {
        $work   = $this->seedLabel('Work');
        $second = $this->seedAccount();

        // Two unread conversations on the first account, one on the second.
        foreach (['First here', 'Second here'] as $subject) {
            $this->thread($subject, unread: 2)->addLabel($work);
        }

        $elsewhere          = $this->thread('Over there', unread: 3);
        $elsewhere->account = $second;
        $elsewhere->addLabel($work);

        $this->em->flush();

        $repository = $this->repository();

        // The row under the first account counts only its own.
        $scoped = $repository->countUnreadPerUserLabel($this->user, $this->account)[(int) $work->id] ?? 0;

        self::assertSame(2, $scoped);
        self::assertCount(
            $scoped,
            $repository->findForLabel($work, $this->account, unreadOnly: true),
            'the account-scoped badge opened a list that was not scoped to its account',
        );

        // While the sidebar's own label row means every account, and its link
        // has to widen to match.
        $across = $repository->countUnreadPerUserLabel($this->user)[(int) $work->id] ?? 0;

        self::assertSame(3, $across);
        self::assertCount($across, $repository->findForLabel($work, null, unreadOnly: true));
    }

    /** Without the flag nothing is filtered — every caller passes it always. */
    public function testTheFilterIsOffByDefault(): void
    {
        $this->thread('Unread', unread: 1);
        $this->thread('Read', unread: 0);

        self::assertCount(2, $this->repository()->findForUnifiedInbox($this->user, MessageCategory::Primary));
    }
}
