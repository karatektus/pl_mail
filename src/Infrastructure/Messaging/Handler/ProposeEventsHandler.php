<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\ProposeEventsMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Calendar\Proposal\EventProposer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Looks for a date in prose in each message of a batch.
 *
 * The loop ProposeEventsStep used to run inside the sync, moved here whole.
 * It still performs no I/O beyond the rows it loads — the body is on the row,
 * the gate is a regex, and most messages are refused by the category check
 * before even that.
 *
 * Flushes its own writes: a proposal that is only persisted is a proposal
 * nobody ever sees.
 */
#[AsMessageHandler]
final readonly class ProposeEventsHandler
{
    public function __construct(
        private MessageRepository      $messages,
        private EventProposer          $proposer,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    public function __invoke(ProposeEventsMessage $message): void
    {
        if ([] === $message->messageIds) {
            return;
        }

        $proposed = 0;

        foreach ($this->messages->findByIds($message->messageIds) as $mail) {
            // Per message, because one message the parser chokes on must not
            // cost the batch its other proposals — and the job is retried as a
            // whole, for a message that will not parse the second time either.
            try {
                if (null !== $this->proposer->propose($mail)) {
                    $proposed++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('EventProposal: proposing failed', [
                    'messageId' => $mail->id,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        if (0 === $proposed) {
            return;
        }

        $this->em->flush();
    }
}
