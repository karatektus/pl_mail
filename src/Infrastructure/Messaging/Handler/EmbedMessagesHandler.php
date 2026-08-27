<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\Ai\AiFeature;
use App\Infrastructure\Messaging\Message\EmbedMessagesMessage;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\User\UserRepository;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\EmbeddingStore;
use App\Service\Ai\MessageEmbedder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Embeds a batch of messages, by id.
 *
 * The one path from "these messages need vectors" to vectors, and every trigger
 * ends here: the nightly app:ai:index-new-mail, and the small batch queued in
 * the warm window after somebody searched. Both go through
 * App\Service\Ai\EmbeddingCatchUp, and neither embeds anything itself — the
 * skip below, the feature check and the transport with a worker of its own all
 * live here, and a second path would re-implement the three of them.
 *
 * Nothing dispatches this on arrival any more. A post-ingest step used to, which
 * spent a round trip to the model host on every message the installation ever
 * received — for a question almost nobody asks, since mail you might search for
 * is rarely mail you read ten minutes ago.
 *
 * Checks the feature again rather than trusting whoever dispatched it: settings
 * change, and a job already on the queue when somebody switches search off must
 * not still spend requests on another machine. That is now two switches — the
 * installation's and the mailbox owner's — which is why the envelope carries a
 * user id at all: this handler had no way to say whose mail it was holding.
 */
#[AsMessageHandler]
final readonly class EmbedMessagesHandler
{
    public function __construct(
        private MessageRepository    $messages,
        private UserRepository       $users,
        private MessageEmbedder      $embedder,
        private EmbeddingStore       $store,
        private AiPermissions        $permissions,
        private AiSettingsRepository $settings,
    ) {
    }

    public function __invoke(EmbedMessagesMessage $message): void
    {
        // First, because it is the one refusal that costs no query at all.
        if ([] === $message->messageIds) {
            return;
        }

        // One find() by primary key per DELIVERY, not per message — and by id,
        // so it is answered from Doctrine's identity map for the rest of this
        // handler. That is cheaper than the ai_settings lookup already paid
        // below, which goes through findOneBy() and issues SQL every time.
        $user = null === $message->userId ? null : $this->users->find($message->userId);

        // Replaces the installation-only check that used to be here: allows()
        // asks the ceiling first, so nothing is lost, and a null user — an
        // envelope from before the id existed — is refused rather than assumed.
        if (false === $this->permissions->allows($user, AiFeature::Search)) {
            return;
        }

        $model = (string) $this->settings->currentOrDefault()->embeddingModel;

        // Skip what is already done under the CURRENT model. A redelivery, or a
        // batch that overlaps a backfill, must not pay for the same vectors
        // twice — and after a model change nothing counts as done, which is
        // what makes a re-embed happen at all.
        $done    = $this->store->alreadyStored($message->messageIds, $model);
        $pending = array_values(array_diff($message->messageIds, $done));

        if ([] === $pending) {
            return;
        }

        $this->embedder->embedAll($user, $this->messages->findByIds($pending));
    }
}
