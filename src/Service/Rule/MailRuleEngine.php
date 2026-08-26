<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Entity\Mail\Account;
use App\Entity\Rule\MailRule;
use App\Entity\Mail\Message;
use App\Jmap\Query\EmailFilterCompiler;
use App\Repository\Rule\MailRuleRepository;
use App\Repository\Mail\MessageRepository;
use Psr\Log\LoggerInterface;

/**
 * Runs a user's mail rules over a batch of freshly synced messages.
 *
 * Matching is done by Postgres, not in PHP. There was briefly a second,
 * in-memory implementation so a message could be tested without a round trip,
 * with a differential test to keep the two honest — but two implementations of
 * "what this filter means" is a standing invitation to drift, and the symptom
 * of drift here is mail quietly filed in the wrong place. One engine cannot
 * disagree with itself. It also unlocks `text`: search_vector is a STORED
 * generated column, so full-text matching is free here and was never faithfully
 * reproducible in PHP.
 *
 * Batch, not per message: one query per rule per batch, rather than one per
 * rule per message.
 *
 * Ordering, which is load-bearing:
 *   - The batch must already be flushed. It is — rules run after the
 *     id-granting flush in all three sync paths, which is also what makes the
 *     generated tsvector available.
 *   - Rules run after threading, so archive/trash actions can reach
 *     Message::$thread.
 *   - Anything mutated in pass 2 but *not yet flushed* is invisible to the
 *     match. Today that is category and thread assignment, neither of which is
 *     a filter condition; labels are set pre-flush in all three builders, so
 *     hasLabel is accurate. Adding a condition over something written later in
 *     pass 2 would silently not see it.
 *
 * Two message paths deliberately bypass this and never trigger rules: the IMAP
 * "Gmailify claim" branch and Gmail's enrichExisting(). Both re-point rows that
 * already exist rather than importing new mail, so a rule firing there would
 * re-file mail the user has already sorted by hand.
 *
 * Best-effort: a broken rule must never fail a sync, so failures are logged and
 * the message is left alone.
 */
final readonly class MailRuleEngine
{
    public function __construct(
        private MailRuleRepository  $ruleRepository,
        private MessageRepository   $messageRepository,
        private EmailFilterCompiler $compiler,
        private RuleActionExecutor  $executor,
        private LoggerInterface     $logger,
    ) {}

    /**
     * @param list<Message> $messages all belonging to $account, already flushed
     */
    public function applyToBatch(array $messages, Account $account): void
    {
        $user = $account->usr;

        if (null === $user || 0 === count($messages)) {
            return;
        }

        $rules = $this->ruleRepository->findEnabledForUserOrdered($user);

        if (0 === count($rules)) {
            return;
        }

        /** @var array<int, Message> $byId */
        $byId = [];

        foreach ($messages as $message) {
            $id = $message->id;

            if (null !== $id) {
                $byId[(int) $id] = $message;
            }
        }

        // Messages a stopProcessing rule has already claimed.
        $finished = [];

        foreach ($rules as $rule) {
            if (false === $rule->appliesTo($account)) {
                continue;
            }

            $candidates = array_values(array_diff(array_keys($byId), $finished));

            if (0 === count($candidates)) {
                return;
            }

            foreach ($this->match($rule, $candidates) as $id) {
                try {
                    $this->executor->execute($rule, $byId[$id]);
                } catch (\Throwable $e) {
                    $this->logger->error('MailRuleEngine: action failed', [
                        'ruleId'    => $rule->id,
                        'messageId' => $id,
                        'error'     => $e->getMessage(),
                        'exception' => $e,
                    ]);
                    continue;
                }

                if (true === $rule->stopProcessing) {
                    $finished[] = $id;
                }
            }
        }
    }

    /**
     * @param list<int> $candidateIds
     *
     * @return list<int>
     */
    private function match(MailRule $rule, array $candidateIds): array
    {
        try {
            return $this->messageRepository->matchingIds(
                $candidateIds,
                $this->compiler->compile($rule->conditions),
            );
        } catch (\Throwable $e) {
            // A stored rule whose conditions no longer compile — the
            // vocabulary moved under it, or it predates validation.
            $this->logger->warning('MailRuleEngine: skipping unmatchable rule', [
                'ruleId'    => $rule->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return [];
        }
    }
}
