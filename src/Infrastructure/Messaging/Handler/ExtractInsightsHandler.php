<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ExtractInsightsMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Insight\InsightHarvester;
use App\Service\Insight\InsightNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Offers a batch of messages to the insight extractors.
 *
 * The loop ExtractInsightsStep used to run inside the sync, moved here whole.
 * The harvester already shields the batch per extractor and per message, so
 * what is left is the flush — once, after the batch, and only when something
 * was actually written.
 *
 * The Mercure announcement belongs here for the same reason the flush does:
 * this is the one place that knows whether the batch wrote anything, and for
 * whom. A publish anywhere else would either fire on a batch that changed
 * nothing (waking every open tab to re-request a fragment that comes back
 * identical) or fire once per insight, which for a catch-up of thirty parcels
 * is thirty identical updates for one strip.
 */
#[AsMessageHandler]
final readonly class ExtractInsightsHandler
{
    public function __construct(
        private MessageRepository      $messages,
        private InsightHarvester       $harvester,
        private InsightNotifier        $notifier,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ExtractInsightsMessage $message): void
    {
        if ([] === $message->messageIds) {
            return;
        }

        $written = 0;

        /** @var array<int, User> $touched users whose strip has something new, keyed by id */
        $touched = [];

        // findByIds() simply omits a message deleted between the dispatch and
        // the run, which is normal: the batch is queued while the mailbox
        // keeps moving.
        foreach ($this->messages->findByIds($message->messageIds) as $mail) {
            $count = $this->harvester->harvest($mail);

            if (0 === $count) {
                continue;
            }

            $written += $count;

            // Keyed by id, so a batch is one update per user however many of
            // their messages carried facts.
            $user = $mail->account->usr;

            if (true === $user instanceof User) {
                $touched[(int) $user->id] = $user;
            }
        }

        if (0 === $written) {
            return;
        }

        $this->em->flush();

        foreach ($touched as $user) {
            $this->notifier->publishInsightsChanged($user);
        }
    }
}
