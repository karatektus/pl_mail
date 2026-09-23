<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\RuleRunState;
use App\Entity\Rule\MailRule;
use App\Infrastructure\Messaging\Message\ApplyMailRuleMessage;
use App\Jmap\Query\EmailFilterCompiler;
use App\Repository\Rule\MailRuleRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Rule\RuleActionExecutor;
use App\Service\Rule\RuleRunNotifier;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Applies a rule to every message it matches, however many that is.
 *
 * Uncapped and asynchronous by design: "apply to existing mail" is the one
 * operation that must reach the whole mailbox, and doing that in a web request
 * means a timeout partway through with no record of how far it got.
 *
 * Progress is written to the rule row after every batch rather than only at
 * the end, so a page reloaded mid-run shows a real count instead of a spinner
 * that might mean anything. Mercure is published alongside it purely to nudge
 * an open page into re-reading; the row is the record.
 */
#[AsMessageHandler]
final readonly class ApplyMailRuleHandler
{
    /**
     * Small enough that a reloaded page sees progress move, large enough that
     * the query cost per message stays low.
     */
    private const int BATCH_SIZE = 200;

    public function __construct(
        private MailRuleRepository     $ruleRepository,
        private MessageRepository      $messageRepository,
        private EmailFilterCompiler    $compiler,
        private RuleActionExecutor     $executor,
        private RuleRunNotifier        $notifier,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    public function __invoke(ApplyMailRuleMessage $message): void
    {
        $rule = $this->ruleRepository->find($message->ruleId);

        if (null === $rule || null === $rule->usr) {
            return;
        }

        $rule->runState = RuleRunState::Running;
        $rule->runProcessed = 0;
        $rule->runStartedAt = new DateTimeImmutable();
        $rule->runFinishedAt = null;
        $this->em->flush();
        $this->notifier->publish($rule);

        $ruleId = (int) $rule->id;

        try {
            $rule = $this->walk($rule);
        } catch (Throwable $e) {
            $this->logger->error('ApplyMailRule: run failed', [
                'ruleId'    => $rule->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            // Recorded rather than swallowed: a run that stopped halfway is
            // something the user has to be able to see and retry. Re-read,
            // because walk() clears the EntityManager between batches and the
            // $rule in hand may no longer be managed. A failed flush also
            // closes the EntityManager — then there is nothing to record into.
            if (false === $this->em->isOpen()) {
                return;
            }

            $this->em->clear();
            $rule = $this->ruleRepository->find($ruleId);

            if (null === $rule) {
                return;
            }

            $rule->runState = RuleRunState::Failed;
            $rule->runFinishedAt = new DateTimeImmutable();
            $this->em->flush();
            $this->notifier->publish($rule);

            return;
        }

        $rule->runState = RuleRunState::Completed;
        $rule->runFinishedAt = new DateTimeImmutable();
        $this->em->flush();
        $this->notifier->publish($rule);
    }

    /**
     * @return MailRule the rule as it is managed NOW — the one passed in is
     *                  detached by the first batch's clear()
     */
    private function walk(MailRule $rule): MailRule
    {
        $ruleId = (int) $rule->id;
        $filter = $this->compiler->compile($rule->conditions);
        $afterId = 0;

        while (true) {
            // The user can be null only if it was deleted mid-run; the rule
            // row goes with it (cascade), so there is nothing left to apply.
            $user = $rule->usr;

            if (null === $user) {
                return $rule;
            }

            $ids = $this->messageRepository->findIdsMatchingForUser(
                $user,
                $filter,
                $afterId,
                self::BATCH_SIZE,
            );

            if (0 === count($ids)) {
                return $rule;
            }

            foreach ($this->messageRepository->findByIds($ids) as $entity) {
                if (false === $rule->appliesTo($entity->account)) {
                    continue;
                }

                $this->executor->execute($rule, $entity);
                $rule->runProcessed++;
            }

            // The cursor advances past the whole page even when some of it was
            // skipped by the account filter — those rows will not match on a
            // later pass either, and re-reading them would loop forever.
            $afterId = $ids[count($ids) - 1];

            $this->em->flush();
            $this->notifier->publish($rule);

            // Without this every message the run has touched stays managed:
            // memory grows with the mailbox, and each flush re-checks every
            // entity of every earlier batch, so the run slows down as it goes.
            // Same discipline as RunBulkStatusHandler and ResortMailboxHandler.
            $this->em->clear();

            $rule = $this->ruleRepository->find($ruleId);

            if (null === $rule) {
                // Deleted while running. Nothing to report progress on.
                throw new \RuntimeException(sprintf('Rule %d disappeared mid-run.', $ruleId));
            }
        }
    }
}
