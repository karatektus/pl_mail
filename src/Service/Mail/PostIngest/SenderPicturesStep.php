<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Enum\Mail\SenderVerdict;
use App\Domain\Helper\OwnAddressHelper;
use App\Domain\Interface\PostIngestStepInterface;
use App\Entity\User\User;
use App\Repository\Mail\Message\MessageSenderPictureRepository;
use App\Service\Mail\SenderAuthentication;
use App\Service\Mail\SenderPicture\SenderPictureQueue;

/**
 * Two things the sender pictures need from mail as it arrives (#639).
 *
 * **Whether each message passed the sender check**, recorded on the message.
 * The headers that say so are in memory now and are not loaded when a list is
 * drawn, and a BIMI logo may only be shown on mail that passed. Recorded
 * whether or not lookups are on, so turning them on later finds the answer
 * already there for everything that arrived in the meantime.
 *
 * **Lookups for the senders**, queued through SenderPictureQueue, which
 * decides whether anything may be asked at all and claims each lookup once
 * however many messages in the batch came from the same sender. Mail the
 * account sent itself is skipped: its own address needs no picture looked up.
 *
 * Here rather than in a queued message, like HarvestContactsStep, because the
 * work is proportional to the batch and the batch is already in memory.
 */
final readonly class SenderPicturesStep implements PostIngestStepInterface
{
    public function __construct(
        private SenderAuthentication           $authentication,
        private MessageSenderPictureRepository $messages,
        private SenderPictureQueue             $queue,
    ) {}

    public function afterCommit(PostIngestResult $result): void
    {
        $verified  = [];
        $addresses = [];
        $users     = [];

        foreach ($result->messages as $message) {
            if (null === $message->id || null === $message->fromAddress || true === OwnAddressHelper::sentByAccount($message)) {
                continue;
            }

            if (SenderVerdict::Pass === $this->authentication->verdict($message)) {
                $verified[] = $message->id;
            }

            $user = $message->account?->usr;

            if ($user instanceof User && null !== $user->id) {
                $users[$user->id]                             = $user;
                $addresses[$user->id][$message->fromAddress] = $message->fromAddress;
            }
        }

        $this->messages->markSenderVerified($verified);

        // Per user, so each user's own switches decide what their mail asks
        // for. A batch is nearly always one user's; a shared mailbox's may
        // not be.
        foreach ($users as $userId => $user) {
            $this->queue->queue([$user], array_values($addresses[$userId]));
        }
    }
}
