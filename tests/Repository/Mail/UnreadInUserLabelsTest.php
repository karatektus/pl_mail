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
 * only shows up on data the default seed does not have. Summing
 * countUnreadPerUserLabel() is one line and reads correctly — but those counts
 * are per label, a conversation may carry several, and adding them reports it
 * once per label. The heading would then promise more unread than expanding the
 * section can show, which is the specific way a rolled-up number stops being
 * believed.
 *
 * So the fixtures here are built to contain exactly that: one thread under two
 * labels. A SUM implementation passes every other test in the suite and fails
 * the first assertion below.
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

        // The per-label rows say 3 and 3 — six between them, over one
        // conversation that has three unread messages in it.
        $perLabel = static::getContainer()->get(MessageThreadRepository::class)
            ->countUnreadPerUserLabel($this->user);

        self::assertSame(3, $perLabel[(int) $work->id]);
        self::assertSame(3, $perLabel[(int) $clients->id]);

        self::assertSame(
            3,
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

        $thread = $this->thread('Binned', unread: 2);
        $thread->addLabel($label);
        $this->em->flush();

        self::assertSame(2, $this->countUnreadInUserLabels());

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
        // different conversations and both belong in the total.
        self::assertSame(6, $this->countUnreadInUserLabels());
    }
}
