<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\Enum\Ai\AiCallFeature;
use App\Entity\Ai\AiFeature;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Ai\AiSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One message, one vector, stored.
 *
 * The thing both callers share — the handler that embeds newly arrived mail and
 * the one that walks an existing mailbox — so that what a message "is", as far
 * as a model is concerned, is decided in exactly one place. Two definitions
 * would drift, and a mailbox embedded by two slightly different descriptions is
 * a mailbox where search quality depends on when a message happened to arrive.
 */
final readonly class MessageEmbedder
{
    /**
     * Characters of body text sent to the model.
     *
     * Embedding models have a context window measured in a few hundred tokens,
     * and everything past it is silently ignored — so a longer budget does not
     * buy more meaning, it buys a slower request that describes the same first
     * page. Long threads are covered by every message in them being embedded
     * separately.
     */
    private const int BODY_BUDGET = 2000;

    public function __construct(
        private AiAssistant            $ai,
        private AiPermissions          $permissions,
        private EmbeddingStore         $store,
        private AiSettingsRepository   $settings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Embed one person's mail.
     *
     * ONE PERSON'S, BY CONSTRUCTION AND BY SIGNATURE
     * ──────────────────────────────────────────────
     * Both callers derive their ids from a per-user query
     * (MessageRepository::unembeddedIdsForUser and ::idsForUserAfter), so the
     * batch already belongs to one mailbox. The parameter makes that invariant
     * something a caller has to state rather than something a reader has to
     * discover — and it is what carries the per-user switch in, since this is
     * one of the four places in src/ that build content for a model.
     *
     * There is deliberately no per-message `$message->account->usr` check
     * inside the loop. That would be two lazy ManyToOne hops per message in a
     * loop that already pays one findOneBy on ai_settings per call, and it
     * would be guarding against a caller that does not exist.
     *
     * @param list<Message> $messages
     *
     * @return int how many were stored
     */
    public function embedAll(?User $user, array $messages): int
    {
        // The installation's switch and this person's together. A null user is
        // refused rather than assumed — see AiPermissions.
        if (false === $this->permissions->allows($user, AiFeature::Search)) {
            return 0;
        }

        $settings = clone $this->settings->currentOrDefault();

        $model  = $settings->embeddingSpace();
        $stored = 0;

        foreach ($messages as $message) {
            $current = $this->settings->currentOrDefault();
            if (!$current->enabledFor(AiFeature::Search) || $current->embeddingSpace() !== $model) {
                break;
            }
            $id = $message->id;

            if (null === $id) {
                continue;
            }

            $vector = $this->ai->embedResult(AiCallFeature::MailIndex, $this->describe($message), $settings)->vector;

            if (null === $vector) {
                // The host is down, the model was deleted, or this message has
                // nothing to describe. None is an application error and none
                // should stop the batch — the next pass picks it up, because
                // nothing was written for it.
                continue;
            }

            $current = $this->settings->currentOrDefault();
            if (!$current->enabledFor(AiFeature::Search) || $current->embeddingSpace() !== $model) {
                break;
            }
            // Claim the width once. A concurrent first batch must not overwrite it.
            if (null === $current->embeddingDimensions) {
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE ai_settings SET embedding_dimensions = :width WHERE id = :id AND embedding_dimensions IS NULL AND embedding_reindex_required = FALSE AND (embedding_approved_space IS NULL OR embedding_approved_space = :space)',
                    ['width' => count($vector), 'id' => $current->id, 'space' => $model],
                );
                $current = $this->settings->currentOrDefault();
            }
            if ($current->embeddingDimensions !== count($vector)) {
                continue;
            }
            if (true === $this->store->store((int) $id, $vector, $model, true)) {
                ++$stored;
            }

        }

        return $stored;
    }

    /**
     * What a message IS, for a model that only sees text.
     *
     * Subject and sender first, because they carry most of the meaning per
     * character and an embedding model weights the start of its window most
     * heavily. No headers, no quoted trail — a reply whose body is mostly the
     * message it answers would otherwise embed as that message.
     */
    private function describe(Message $message): string
    {
        $body = trim((string) ($message->bodyText ?? ''));

        return trim(implode("\n", [
            trim((string) $message->subject),
            trim(((string) $message->fromName) . ' ' . ((string) $message->fromAddress)),
            '',
            mb_substr($body, 0, self::BODY_BUDGET),
        ]));
    }
}
