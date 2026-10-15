<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\MessageThread;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\InboxCategoryRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Mail\InboxTabs;
use App\Service\Mail\ListViewResolver;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Select everything in this view", while looking at a tab the reader made.
 *
 * The view is posted as a scope and a value, and the value is the tab's key. If
 * it were read as a built-in category only, selecting all while on Shipping and
 * pressing archive would act on PRIMARY — mail that is not on screen — which is
 * the one mistake a whole-view action must never make.
 */
final class ListViewResolverCustomTabTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private ListViewResolver $resolver;
    private InboxCategory $shipping;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // Built by hand: a service with one consumer is inlined away.
        $this->resolver = new ListViewResolver(
            $container->get(MessageThreadRepository::class),
            $container->get(LabelRepository::class),
            new InboxTabs($container->get(InboxCategoryRepository::class)),
        );

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

    public function testTheViewOfAnOwnTabIsThatTabsConversationsAndNothingElse(): void
    {
        $filed = $this->thread('a notice', MessageCategory::Promotions, unread: 1);
        $filed->userCategory = $this->shipping;
        $this->thread('a normal one', unread: 1);
        $this->em->flush();

        self::assertSame(['a notice'], $this->subjects('c' . $this->shipping->id));
        self::assertSame(['a normal one'], $this->subjects('primary'), 'a conversation filed elsewhere is in the built-in view as well');
    }

    /**
     * A tab that has gone — deleted on another device while this page was open,
     * or somebody else's — selects NOTHING. It must not fall back to Primary the
     * way a mistyped built-in name does: a whole-view action is queued from the
     * stale page and runs later, and "archive everything" over a view that no
     * longer exists would archive up to ten thousand Primary conversations that
     * were never on screen. The same answer a label that is not yours gets.
     */
    public function testATabThatHasGoneSelectsNothingRatherThanReachingPrimary(): void
    {
        $this->thread('a normal one', unread: 1);

        self::assertSame([], $this->subjects('c999999'));
    }

    /**
     * The fall-back that stays, as the companion: an unknown NAME is a mistyped
     * built-in and keeps meaning Primary, exactly as before custom tabs.
     */
    public function testAnUnknownBuiltInNameStillMeansPrimary(): void
    {
        $this->thread('a normal one', unread: 1);

        self::assertSame(['a normal one'], $this->subjects('quatsch'));
    }

    /** @return list<string> */
    private function subjects(string $key): array
    {
        $subjects = array_map(
            static fn (MessageThread $thread): string => (string) $thread->subject,
            $this->resolver->threadsIn($this->user, 'inbox', $key, false),
        );
        sort($subjects);

        return $subjects;
    }
}
