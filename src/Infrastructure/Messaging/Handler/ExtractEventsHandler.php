<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ExtractEventsMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Calendar\CalendarNotifier;
use App\Service\Calendar\EventReconciler;
use App\Service\Calendar\Extraction\EventExtractionRunner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Finds events in a batch of messages and puts them on a calendar.
 *
 * Off the sync path deliberately — see ExtractEventsMessage. Failures are
 * per-message: one unparseable invite must not cost the batch, and the whole
 * batch must not be retried for it, because a message that cannot be parsed
 * will not parse on the second attempt either.
 */
#[AsMessageHandler]
final readonly class ExtractEventsHandler
{
    public function __construct(
        private MessageRepository      $messages,
        private EventExtractionRunner  $runner,
        private EventReconciler        $reconciler,
        private CalendarNotifier       $notifier,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    public function __invoke(ExtractEventsMessage $message): void
    {
        /** @var array<int, User> $usersToNotify */
        $usersToNotify = [];
        $found         = 0;

        foreach ($message->messageIds as $messageId) {
            $mail = $this->messages->find($messageId);

            if (null === $mail) {
                // Deleted between the dispatch and the run. Normal, not an
                // error: the batch is queued while the mailbox keeps moving.
                continue;
            }

            try {
                $extracted = $this->runner->run($mail);

                if ([] === $extracted) {
                    continue;
                }

                $touched = $this->reconciler->reconcile($mail, $extracted);

                if ([] === $touched) {
                    // Everything it found was suppressed, superseded, or on an
                    // event the user has edited. All three are outcomes, not
                    // failures.
                    continue;
                }

                // Per message, not once for the batch: a single bad row would
                // otherwise take every event the batch found with it, and the
                // job is retried as a whole.
                $this->em->flush();

                $found += count($touched);

                $user = $mail->account->usr;

                if (true === $user instanceof User) {
                    $usersToNotify[(int) $user->id] = $user;
                }
            } catch (\Throwable $e) {
                $this->logger->error('ExtractEvents: extraction failed for a message', [
                    'messageId' => $messageId,
                    'error'     => $e->getMessage(),
                ]);
            }

            // Doctrine closes the manager on a failed flush, and everything
            // after it throws — including the provider calls that materialise
            // a part. Carrying on is not resilience, it is spending quota on
            // work that cannot be saved.
            if (false === $this->em->isOpen()) {
                $this->logger->error('ExtractEvents: entity manager closed, abandoning the batch', [
                    'messageId' => $messageId,
                ]);

                return;
            }
        }

        if (0 === $found) {
            return;
        }

        // Each message was flushed as it went, so by here the rows are
        // committed — a page told to look again any earlier would see the
        // calendar it already had.
        foreach ($usersToNotify as $user) {
            $this->notifier->publishCalendarChanged($user);
        }

        $this->logger->info('ExtractEvents: events reconciled', [
            'messages' => count($message->messageIds),
            'events'   => $found,
        ]);
    }
}
