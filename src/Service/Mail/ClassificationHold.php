<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Ai\AiFeature;
use App\Entity\Embeddable\CategorySorting;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiPermissions;
use App\Service\Mail\PostIngest\RecentMailPolicy;

/**
 * Decides whether an arriving message waits for the assistant before it is
 * shown in a tab.
 *
 * What holding IS lives on Message::$categoryHeldAt, and what ends it lives in
 * ClassifyMailHandler. This is only the decision, and it is its own class
 * because the decision is a list of reasons NOT to hold — every one of them a
 * case where waiting would cost seconds and change nothing.
 *
 * NARROW ON PURPOSE. A held message is mail somebody cannot see yet, which is
 * the most expensive thing a mail client can do to its user, so it is done
 * only where all of these are true:
 *
 *   · the installation has holding switched on, and sorting by assistant at all;
 *   · this person lets the assistant sort their mail;
 *   · the mail is trickling in rather than being imported — the caller's
 *     $live, from InitialImportState. An import that held its mail would put a
 *     mailbox behind a model;
 *   · the mail is recent, and not this account's own outgoing copy;
 *   · and the verdict could actually move it. See
 *     MessageCategorizer::verdictCouldDecide() — a reader sorting by rules, a
 *     Gmail label and a known correspondent are all answers that are already
 *     final.
 *
 * NOT CHECKED: WHETHER THE MAIL IS IN THE INBOX. The user's own rules run
 * after threading and may archive it, and the thread's category has to be
 * decided before threading. A held message that a rule then archives is held
 * for nothing — it was never going to be in a tab — and that costs one
 * prioritised model call, which it would have had anyway.
 */
final readonly class ClassificationHold
{
    public function __construct(
        private AiSettingsRepository $settings,
        private AiPermissions        $permissions,
        private MessageCategorizer   $categorizer,
        private RecentMailPolicy     $recent,
    ) {
    }

    /**
     * The half of the decision that is the same for every message in a batch.
     *
     * Split off so the pipeline asks it once: it is a settings read, and the
     * other half is called per message.
     */
    public function isOnFor(?User $user): bool
    {
        if (null === $user) {
            return false;
        }

        if (false === $this->settings->currentOrDefault()->holdUntilClassified) {
            return false;
        }

        return $this->permissions->allows($user, AiFeature::Categorise);
    }

    /**
     * @param array<string,true> $correspondentEmails
     */
    public function shouldHold(
        Message $message,
        Account $account,
        array $correspondentEmails,
        ?CategorySorting $sorting,
    ): bool {
        // Asked about already — a row re-ingested after a move, say. The answer
        // is on it and there is nothing to wait for.
        if (null !== $message->aiCategorisedAt) {
            return false;
        }

        if (false === $this->recent->isRecent($message)) {
            return false;
        }

        if (true === $this->isOwnMail($message, $account)) {
            return false;
        }

        return $this->categorizer->verdictCouldDecide($message, $correspondentEmails, $sorting);
    }

    /**
     * The Sent copy of something this account wrote.
     *
     * It is ingested like any other message and is never in an inbox tab, so
     * holding it would be a model call jumping the queue to sort mail nobody
     * is going to look for there.
     */
    private function isOwnMail(Message $message, Account $account): bool
    {
        $from = mb_strtolower(trim((string) $message->fromAddress));

        return '' !== $from && $from === mb_strtolower(trim((string) $account->email));
    }
}
