<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\ReleaseHeldMailMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\HeldMailReleaser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The end of the wait: shows held mail where the rules put it.
 *
 * Delivered after AiSettings::$holdMaxSeconds, and in the ordinary case finds
 * nothing to do — the assistant answered and ClassifyMailHandler released the
 * message long before. What it does find is the case the delay was for: no
 * answer yet. The question is still open and its answer will move the mail;
 * this only stops making the reader wait for it. See ReleaseHeldMailMessage.
 *
 * The category is not touched. The rules' answer was written when the message
 * arrived and is still on the row, which is the whole reason it was written;
 * all that is missing is letting the thread adopt it.
 */
#[AsMessageHandler]
final readonly class ReleaseHeldMailHandler
{
    public function __construct(
        private MessageRepository $messages,
        private HeldMailReleaser  $releaser,
        private LoggerInterface   $logger,
    ) {
    }

    public function __invoke(ReleaseHeldMailMessage $message): void
    {
        if ([] === $message->messageIds) {
            return;
        }

        $released = $this->releaser->release($this->messages->findByIds($message->messageIds));

        if (0 === $released) {
            return;
        }

        // Worth a line: each of these is a message the assistant did not
        // answer for in time, and a steady stream of them is a host too slow
        // for the wait it has been given.
        $this->logger->info('Held mail shown before the assistant answered', [
            'messages' => $released,
        ]);
    }
}
