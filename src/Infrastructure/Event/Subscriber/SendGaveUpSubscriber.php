<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Domain\Enum\Mail\SendFailure;
use App\Domain\Exception\SendFailureException;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\SendOutcomeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Marks a draft whose send the export transport gave up on, and says so (#125).
 *
 * SendMessageHandler ends most failed sends itself: a refusal, a lost answer
 * that cannot be checked, a wait that would outlast two minutes. What it does
 * not see is the last of Messenger's retries running out — its own failures
 * are rethrown to be retried, and whether a retry follows is decided after it
 * has returned. A rate limit that kept answering inside the two minutes, a
 * token that would not refresh: those used to end on the failed queue with
 * nothing said, the draft unmarked and the toast never shown. This is where
 * they end now.
 *
 * Runs after SendFailedMessageForRetryListener (priority 100), which is what
 * decides willRetry(): a failure that will be retried is not the end yet.
 */
final readonly class SendGaveUpSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageRepository      $messages,
        private EntityManagerInterface $em,
        private SendOutcomeNotifier    $outcomes,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => ['onFailed', 0],
        ];
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $send = $event->getEnvelope()->getMessage();

        if (false === $send instanceof SendMessageMessage || true === $event->willRetry()) {
            return;
        }

        // The handler put the claim down before rethrowing; this is the same
        // statement again, for a failure that left before it could.
        $this->messages->releaseSendClaim($send->messageId);
        $this->messages->markSendFailed($send->messageId, self::failureIn($event->getThrowable()));

        $message = $this->messages->find($send->messageId);

        if (null === $message) {
            return;
        }

        // The row was written by a bulk update just now; what the identity
        // map holds from the attempt is older than that.
        $this->em->refresh($message);

        // Nothing to say about a mail that went out after all — markSendFailed
        // never marks one.
        if (null !== $message->sendFailedAt) {
            $this->outcomes->failed($message);
        }
    }

    /** What the senders said it was, or Failed when nothing they threw says. */
    private static function failureIn(\Throwable $thrown): SendFailure
    {
        $candidates = $thrown instanceof HandlerFailedException ? $thrown->getWrappedExceptions() : [$thrown];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof SendFailureException) {
                return $candidate->failure();
            }
        }

        return SendFailure::Failed;
    }
}
