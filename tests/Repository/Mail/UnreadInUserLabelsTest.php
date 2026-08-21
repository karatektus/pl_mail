<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Repository\Mail\MessageThreadRepository;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The number the collapsed LABELS heading shows.
 *
 * Worth its own file because the obvious implementation is wrong in a way that
 * only shows up on data the default seed does not have. Adding up
 * countUnreadPerUserLabel() is one line and reads correctly — but those counts
 * are per label, a conversation may carry several, and adding them reports it
 * once per label. The heading would then promise more unread than expanding the
 * section can show, which is the specific way a rolled-up number stops being
 * believed.
 *
 * So the fixtures here are built to contain exactly that: one thread under two
 * labels. An adding implementation passes every other test in the suite and
 * fails the first assertion below.
 *
 * Every number here counts CONVERSATIONS holding unread mail, not unread
 * messages — the badges became clickable, and a badge you click to see the mail
 * it counted has to say how many rows you will get. The fixtures deliberately
 * give their threads several unread messages each, so the old message-summing
 * implementation would produce a visibly different number rather than the same
 * one by luck.
 */
final class UnreadInUserLabelsTest extends KernelTestCase
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

    private function countUnreadInUserLabels(): int
    {
        return static::getContainer()
            ->get(MessageThreadRepository::class)
            ->countUnreadInUserLabels($this->user);
    }

    public function testAThreadUnderTwoLabelsCountsOnce(): void
    {
        $work    = $this->seedLabel('Work');
        $clients = $this->seedLabel('Clients');

        $thread = $this->thread('Double filed', unread: 3);
        $thread->addLabel($work);
        $thread->addLabel($clients);
        $this->em->flush();

        // One conversation under each label, so each row says 1 — not 3, which
        // is how many unread MESSAGES are in it. Both rows open a list holding
        // exactly the one thread they counted.
        $perLabel = static::getContainer()->get(MessageThreadRepository::class)
            ->countUnreadPerUserLabel($this->user);

        self::assertSame(1, $perLabel[(int) $work->id]);
        self::assertSame(1, $perLabel[(int) $clients->id]);

        self::assertSame(
            1,
            $this->countUnreadInUserLabels(),
            'the roll-up counted the same conversation once per label on it',
        );
    }

    public function testMailWithNoCustomLabelIsNotCountedAtAll(): void
    {
        // Inbox only, which is a system label — the section this number belongs
        // to lists custom labels, so nothing here is under it.
        $this->thread('Just in the inbox', unread: 5);

        self::assertSame(0, $this->countUnreadInUserLabels());
    }

    public function testReadMailUnderALabelAddsNothing(): void
    {
        $label = $this->seedLabel('Archive pile');

        $thread = $this->thread('Already read', unread: 0);
        $thread->addLabel($label);
        $this->em->flush();

        self::assertSame(0, $this->countUnreadInUserLabels());
    }

    /**
     * The same rule the per-label counts follow, so the heading cannot be
     * louder than the rows underneath it.
     */
    public function testATrashedThreadStopsCounting(): void
    {
        $label = $this->seedLabel('Work');
        $trash = $this->seedLabel('Trash', LabelRole::Trash);

        // One conversation, two unread messages in it: the heading says 1.
        $thread = $this->thread('Binned', unread: 2);
        $thread->addLabel($label);
        $this->em->flush();

        self::assertSame(1, $this->countUnreadInUserLabels());

        $thread->addLabel($trash);
        $this->em->flush();

        self::assertSame(0, $this->countUnreadInUserLabels());
    }

    public function testSeparateThreadsStillAddUp(): void
    {
        $work    = $this->seedLabel('Work');
        $clients = $this->seedLabel('Clients');

        $first = $this->thread('One', unread: 2);
        $first->addLabel($work);

        $second = $this->thread('Two', unread: 4);
        $second->addLabel($clients);

        $this->em->flush();

        // De-duplicating must not turn into under-counting: these are two
        // different conversations and both belong in the total. Two, not the
        // six unread messages they hold between them — and the two numbers are
        // deliberately different so this cannot pass under either rule by
        // accident.
        self::assertSame(2, $this->countUnreadInUserLabels());
    }
}
