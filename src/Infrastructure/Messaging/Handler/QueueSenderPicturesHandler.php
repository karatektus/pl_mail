<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\QueueSenderPicturesMessage;
use App\Repository\Mail\Message\MessageSenderPictureRepository;
use App\Repository\User\User\UserRepository;
use App\Service\Mail\SenderPicture\SenderPictureQueue;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Queues lookups for everyone who has written to one user lately (#639) —
 * see QueueSenderPicturesMessage for when.
 *
 * "Lately" is the last ninety days and at most a thousand senders, most recent
 * first. Past that is mail nobody is looking at, and asking about every
 * address in a ten-year archive would be tens of thousands of requests to
 * strangers on the strength of one click. Older senders get a picture the
 * next time they write.
 */
#[AsMessageHandler]
final readonly class QueueSenderPicturesHandler
{
    private const int DAYS = 90;

    private const int MAX_SENDERS = 1000;

    public function __construct(
        private UserRepository                 $users,
        private MessageSenderPictureRepository $messages,
        private SenderPictureQueue             $queue,
        private LoggerInterface                $logger,
    ) {}

    public function __invoke(QueueSenderPicturesMessage $message): void
    {
        $user = $this->users->find($message->userId);

        if (null === $user) {
            return;
        }

        $senders = $this->messages->recentSenders($user, new \DateTimeImmutable(sprintf('-%d days', self::DAYS)), self::MAX_SENDERS);
        $queued  = $this->queue->queue([$user], $senders);

        $this->logger->info('QueueSenderPicturesHandler: lookups queued', [
            'userId'  => $message->userId,
            'senders' => count($senders),
            'queued'  => $queued,
        ]);
    }
}
