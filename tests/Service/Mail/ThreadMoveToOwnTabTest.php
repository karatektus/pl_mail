<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\InboxCategory;
use App\Service\Mail\ThreadStatusUpdater;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Dropping a conversation on a tab the reader made, and on a built-in one from
 * there.
 *
 * Two ways to get it wrong, and both leave the strip lying: a drop onto the
 * reader's own tab that rewrites the BUILT-IN category (JMAP clients are served
 * that column, so their phone would show the conversation in a tab the browser
 * has never heard of), and a drop back onto a built-in tab that leaves the
 * conversation in the reader's tab as well (it would be in two tabs, or in the
 * one the person just moved it out of).
 *
 * Both drops are pinned, for the reason ThreadMoveTest gives for the built-in
 * ones: most-recent-wins would otherwise undo the move at the next message.
 */
final class ThreadMoveToOwnTabTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private ThreadStatusUpdater $status;
    private InboxCategory $shipping;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->status     = $container->get(ThreadStatusUpdater::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->shipping       = new InboxCategory();
        $this->shipping->usr  = $this->user;
        $this->shipping->name = 'Shipping';
        $this->em->persist($this->shipping);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testADropOnTheReadersOwnTabFilesAndPinsItAndLeavesTheBuiltInCategoryAlone(): void
    {
        $thread = $this->thread('a notice', MessageCategory::Promotions, unread: 1);

        self::assertTrue($this->status->setCategory($thread, $this->shipping));

        self::assertSame($this->shipping, $thread->userCategory);
        self::assertNotNull($thread->categoryPinnedAt, 'the drop is not pinned, so the next message undoes it');
        self::assertSame(
            MessageCategory::Promotions,
            $thread->category,
            'a drop on the reader\'s own tab rewrote the built-in category JMAP serves',
        );
    }

    public function testADropBackOntoABuiltInTabTakesItOutOfTheReadersTab(): void
    {
        $thread = $this->thread('a notice', MessageCategory::Promotions, unread: 1);
        $this->status->setCategory($thread, $this->shipping);

        self::assertTrue($this->status->setCategory($thread, MessageCategory::Primary));

        self::assertNull($thread->userCategory, 'the conversation is in a built-in tab AND the reader\'s own');
        self::assertSame(MessageCategory::Primary, $thread->category);
        self::assertNotNull($thread->categoryPinnedAt);
    }

    /**
     * Dropping where it already is changes nothing, and says so — for the reader's
     * own tab as for the five. The companion is the pair above: the same call on
     * a conversation that is NOT there yet does change it.
     */
    public function testADropWhereTheConversationAlreadyIsIsNotAChange(): void
    {
        $thread = $this->thread('a notice', MessageCategory::Promotions, unread: 1);

        self::assertTrue($this->status->setCategory($thread, $this->shipping));
        self::assertFalse($this->status->setCategory($thread, $this->shipping), 'a repeated drop reported a change');

        self::assertTrue($this->status->setCategory($thread, MessageCategory::Primary));
        self::assertFalse($this->status->setCategory($thread, MessageCategory::Primary));
    }

    /**
     * A built-in drop onto a conversation that is pinned to a built-in tab but
     * ALSO has a tab of the reader's: the built-in column already matches, and
     * "nothing to do" would leave it in the reader's tab. The case the old
     * equality check would have got wrong.
     */
    public function testABuiltInDropStillMovesAConversationWhoseBuiltInCategoryAlreadyMatches(): void
    {
        $thread                   = $this->thread('a notice', MessageCategory::Promotions, unread: 1);
        $thread->userCategory     = $this->shipping;
        $thread->categoryPinnedAt = new \DateTimeImmutable();
        $this->em->flush();

        self::assertTrue($this->status->setCategory($thread, MessageCategory::Promotions));

        self::assertNull($thread->userCategory);
    }
}
