<?php

declare(strict_types=1);

namespace App\Service\Task\Alert;

use App\Entity\Task\Task;
use App\Repository\Task\TaskAlertDeliveryRepository;
use App\Repository\Task\TaskRepository;
use App\Service\Calendar\Alert\PushAlertChannel;
use App\Service\Calendar\CalendarTimeResolver;
use App\Service\Push\UserPushNotifier;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Sending a task's reminder, exactly once.
 *
 * Claim first, then send, as AlertDeliverer does and for its reasons; a delivery
 * that fails is a delivery that is lost, and nothing retries it.
 *
 * Web Push only, as the design has it: a reminder has no email action to honour,
 * and a service that quietly mails you instead is one you stop trusting.
 */
final readonly class TaskAlertDeliverer
{
    /**
     * How late a reminder may still go out. A worker back from a long outage should
     * say what is about to happen, not read out everything it missed.
     */
    private const string LOOKBACK = '-1 hour';

    public function __construct(
        private TaskRepository              $tasks,
        private TaskAlertDeliveryRepository $deliveries,
        private UserPushNotifier            $notifier,
        private CalendarTimeResolver        $time,
        private TranslatorInterface         $translator,
        private LoggerInterface             $logger,
    ) {}

    /** @return list<Task> */
    public function due(DateTimeImmutable $now): array
    {
        return $this->tasks->findDueReminders($now->modify(self::LOOKBACK), $now);
    }

    /** @return bool whether this call owned the reminder, delivered or not */
    public function deliver(Task $task, ?DateTimeImmutable $now = null): bool
    {
        $reminderAt = $task->reminderAt;

        if (null === $reminderAt || false === $this->deliveries->claim($task, $reminderAt, $now)) {
            return false;
        }

        // One device that throws must not end the minute's sweep for everybody.
        try {
            $delivered = $this->notifier->notify((int) $task->usr->id, $this->payload($task));
        } catch (Throwable $e) {
            $this->logger->error('TaskAlert: the notifier threw instead of refusing', [
                'taskId'    => $task->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return true;
        }

        if (false === $delivered) {
            $this->logger->info('TaskAlert: nowhere to deliver it', ['taskId' => $task->id, 'userId' => $task->usr->id]);
        }

        return true;
    }

    /**
     * @return array<string, string>
     *
     * The calendar's payload type, so the service worker and the phone app show it
     * with no change of their own: they read a title, a body and where to go. The
     * tag lets a replayed push replace its notification rather than stack beside it.
     */
    private function payload(Task $task): array
    {
        $title = trim($task->title);

        return [
            '@type' => PushAlertChannel::PAYLOAD_TYPE,
            'title' => '' === $title ? $this->translator->trans('tasks.alert.untitled') : $title,
            'body'  => $this->body($task),
            'url'   => '/tasks',
            'tag'   => sprintf('task/%d/%s', (int) $task->id, $task->reminderAt?->format('Y-m-d\TH:i:s\Z')),
        ];
    }

    /** When it is due, on the owner's clock; a date has no time to show. */
    private function body(Task $task): string
    {
        $locale = $task->usr->locale;

        if (null !== $task->dueDate) {
            return $this->translator->trans('tasks.alert.due', ['%when%' => $task->dueDate->format('Y-m-d')], null, $locale);
        }

        if (null !== $task->dueAt) {
            $when = $task->dueAt->setTimezone($this->time->zoneFor($task->usr))->format('Y-m-d H:i');

            return $this->translator->trans('tasks.alert.due', ['%when%' => $when], null, $locale);
        }

        return $this->translator->trans('tasks.alert.reminder', [], null, $locale);
    }
}
