<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Repository\Mail\Message\MessageListBadgeRepository;
use App\Repository\Mail\MessageThread\MessageThreadBadgeRepository;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The number a parent label shows while its tree is collapsed (#807).
 *
 * The same trap as the LABELS heading (UnreadInUserLabelsTest): the parent's
 * count plus each child's is one line, and it counts a conversation filed under
 * the parent and a child twice. So the fixture holds exactly that conversation,
 * and a conversation under a label OUTSIDE the tree, which must not leak in.
 */
final class UnreadInLabelTreeTest extends KernelTestCase
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

    public function testTheTreeCountsItsChildrenAndEachConversationOnce(): void
    {
        $work     = $this->seedLabel('Work');
        $invoices = $this->child('Invoices', $work);
        $clients  = $this->child('Clients', $work);
        $private  = $this->seedLabel('Private');

        // Under the parent AND a child: one conversation.
        $both = $this->thread('Filed twice', unread: 3);
        $both->addLabel($work);
        $both->addLabel($invoices);

        // Only under a child — the mail a collapsed parent used to hide.
        $childOnly = $this->thread('Only in a child', unread: 1);
        $childOnly->addLabel($clients);

        // Outside the tree altogether.
        $elsewhere = $this->thread('Somewhere else', unread: 2);
        $elsewhere->addLabel($private);

        $this->em->flush();

        $tree = [(int) $work->id, (int) $invoices->id, (int) $clients->id];

        self::assertSame(
            2,
            static::getContainer()->get(MessageThreadBadgeRepository::class)
                ->countUnreadInLabels($this->user, $tree),
            'conversations in the tree, each once, and nothing from outside it',
        );
    }

    public function testATrashedConversationStopsCounting(): void
    {
        $work     = $this->seedLabel('Work');
        $invoices = $this->child('Invoices', $work);
        $trash    = $this->seedLabel('Trash', LabelRole::Trash);

        $thread = $this->thread('Binned', unread: 1);
        $thread->addLabel($invoices);
        $this->em->flush();

        $repository = static::getContainer()->get(MessageThreadBadgeRepository::class);
        $tree       = [(int) $work->id, (int) $invoices->id];

        self::assertSame(1, $repository->countUnreadInLabels($this->user, $tree));

        $this->binThread($thread, $trash);

        self::assertSame(0, $repository->countUnreadInLabels($this->user, $tree));
    }

    /**
     * The flat view (#615) counts emails, not conversations — the same switch
     * every other badge follows. An email wearing the parent and a child is
     * still one.
     */
    public function testTheFlatViewCountsEachEmailOnce(): void
    {
        $work     = $this->seedLabel('Work');
        $invoices = $this->child('Invoices', $work);

        $both = $this->thread('Parent and child', unread: 1)->messages->first();
        self::assertNotFalse($both);
        $both->addLabel($work);
        $both->addLabel($invoices);

        $childOnly = $this->thread('Child only', unread: 1)->messages->first();
        self::assertNotFalse($childOnly);
        $childOnly->addLabel($invoices);

        $this->em->flush();

        self::assertSame(
            2,
            static::getContainer()->get(MessageListBadgeRepository::class)
                ->countUnreadInLabels($this->user, [(int) $work->id, (int) $invoices->id]),
        );
    }

    private function child(string $name, Label $parent): Label
    {
        $label         = $this->seedLabel($name);
        $label->parent = $parent;
        $this->em->flush();

        return $label;
    }
}
