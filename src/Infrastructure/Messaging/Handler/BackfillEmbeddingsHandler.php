<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\Ai\AiFeature;
use App\Infrastructure\Messaging\Message\BackfillEmbeddingsMessage;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\User\UserRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\EmbeddingStore;
use App\Service\Ai\MessageEmbedder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Walks one mailbox, a chunk at a time, embedding what has not been embedded.
 *
 * RE-DISPATCHES ITSELF RATHER THAN LOOPING
 * ────────────────────────────────────────
 * A hundred thousand messages at a round trip each is hours. A single job
 * holding that would be killed by any worker restart with nothing to show for
 * it and would begin again from the start; a loop inside one handler would hold
 * a database connection and a transport lease for the whole time.
 *
 * So each delivery does a chunk, records nothing but the vectors themselves,
 * and posts the next cursor. Interrupting it at any point loses at most one
 * chunk, and starting it again resumes from wherever the vectors stop — the
 * work already done IS the progress record, which is why there is no separate
 * one to keep in step.
 *
 * ASCENDING ID, NOT DATE
 * ──────────────────────
 * The one ordering nothing can change underneath a long walk. Mail arriving
 * during a backfill gets a higher id and is met by the pass still coming; mail
 * deleted during one is simply absent. A date cursor would have to cope with
 * both, and with two messages sharing a timestamp.
 */
#[AsMessageHandler]
final readonly class BackfillEmbeddingsHandler
{
    /**
     * Messages per delivery.
     *
     * Small, because every one is a request to another machine and a chunk is
     * the unit of work that survives a restart. Fifty at a second each is under
     * a minute per delivery, which keeps the transport's visibility timeout
     * comfortable and lets somebody switch the feature off and see it stop.
     */
    private const int CHUNK = 50;

    public function __construct(
        private UserRepository         $users,
        private MessageRepository      $messages,
        private MessageEmbedder        $embedder,
        private EmbeddingStore         $store,
        private AiAssistant            $ai,
        private AiSettingsRepository   $settings,
        private MessageBusInterface    $bus,
        private EntityManagerInterface $entityManager,
        private LoggerInterface        $logger,
    ) {
    }

    public function __invoke(BackfillEmbeddingsMessage $message): void
    {
        // Every chunk asks again, so switching search off stops the walk within
        // one chunk rather than at the end of the mailbox.
        if (false === $this->ai->isEnabledFor(AiFeature::Search)) {
            $this->logger->info('BackfillEmbeddings: stopping, the feature is off', [
                'userId' => $message->userId,
            ]);

            return;
        }

        $user = $this->users->find($message->userId);

        if (null === $user) {
            return;
        }

        $ids = $this->messages->idsForUserAfter($message->userId, $message->afterMessageId, self::CHUNK);

        if ([] === $ids) {
            $this->logger->info('BackfillEmbeddings: finished', [
                'userId'    => $message->userId,
                'embedded'  => $this->store->countFor((string) $this->settings->currentOrDefault()->embeddingModel),
            ]);

            return;
        }

        $model   = (string) $this->settings->currentOrDefault()->embeddingModel;
        $done    = $this->store->alreadyStored($ids, $model);
        $pending = array_values(array_diff($ids, $done));

        if ([] !== $pending) {
            $this->embedder->embedAll($this->messages->findByIds($pending));
        }

        // The cursor advances past everything LOOKED AT, not everything stored.
        // A message the model could not answer for must not become a wall the
        // walk restarts at forever; the next full run picks it up because
        // nothing was written for it.
        $cursor = max($ids);

        // Cleared before posting the next chunk: this handler has walked a
        // batch of entities it will never look at again, and a long walk that
        // keeps every one is killed for memory rather than finishing.
        $this->entityManager->clear();

        $this->bus->dispatch(new BackfillEmbeddingsMessage($message->userId, $cursor));
    }
}
