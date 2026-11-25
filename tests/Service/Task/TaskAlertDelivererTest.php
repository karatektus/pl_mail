<?php

declare(strict_types=1);

namespace App\Tests\Service\Task;

use App\Domain\DTO\Task\TaskDraft;
use App\Entity\Task\Task;
use App\Entity\User\User;
use App\Service\Task\Alert\TaskAlertDeliverer;
use App\Service\Task\TaskListProvisioner;
use App\Service\Task\TaskWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A reminder goes off once, and only when it means something.
 *
 * A second sweep a minute later, a task already finished, and a worker back from a
 * long outage are the three ways this nags somebody. Against the real table,
 * because the guarantee is its unique index and not any line of PHP.
 */
final class TaskAlertDelivererTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private TaskAlertDeliverer $deliverer;
    private TaskWriter $writer;
    private TaskListProvisioner $provisioner;
    private User $user;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();

        $container         = self::getContainer();
        $this->em          = $container->get(EntityManagerInterface::class);
        $this->connection  = $container->get(Connection::class);
        $this->deliverer   = $container->get(TaskAlertDeliverer::class);
        $this->writer      = $container->get(TaskWriter::class);
        $this->provisioner = $container->get(TaskListProvisioner::class);
        $this->now         = new DateTimeImmutable('2030-06-15 12:00:00', new DateTimeZone('UTC'));

        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = sprintf('task-alert-%s@example.test', uniqid());
        $this->user->nameFirst = 'Task';
        $this->user->nameLast  = 'Alert';
        $this->user->password  = 'x';
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testADueReminderIsFoundAndClaimedOnce(): void
    {
        $task = $this->task('Due', '-5 minutes');

        self::assertContains($task, $this->deliverer->due($this->now));
        self::assertTrue($this->deliverer->deliver($task, $this->now));
        self::assertFalse($this->deliverer->deliver($task, $this->now), 'the second sweep is told the first has it');
        self::assertNotContains($task, $this->deliverer->due($this->now));
    }

    public function testAReminderNotYetDueIsLeftAlone(): void
    {
        self::assertNotContains($this->task('Later', '+5 minutes'), $this->deliverer->due($this->now));
    }

    public function testAReminderLongPastIsNotReadOutAfterAnOutage(): void
    {
        self::assertNotContains($this->task('Stale', '-3 hours'), $this->deliverer->due($this->now));
    }

    public function testAFinishedTaskDoesNotRemindAnybody(): void
    {
        $task = $this->task('Done', '-5 minutes');
        $this->writer->complete($task);
        $this->em->flush();

        self::assertNotContains($task, $this->deliverer->due($this->now));
    }

    public function testARepeatingTasksNextReminderIsADifferentReminder(): void
    {
        $task = $this->task('Weekly', '-5 minutes');
        $this->deliverer->deliver($task, $this->now);

        // What TaskRecurrence does to it when ticked off.
        $task->reminderAt = $task->reminderAt?->modify('+1 week');
        $this->em->flush();

        self::assertTrue($this->deliverer->deliver($task, $this->now));
    }

    private function task(string $title, string $offset): Task
    {
        $task             = $this->writer->create($this->user, $this->provisioner->defaultFor($this->user), new TaskDraft($title));
        $task->reminderAt = $this->now->modify($offset);
        $this->em->flush();

        return $task;
    }
}
