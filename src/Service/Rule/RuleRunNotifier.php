<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Domain\Helper\ThrowableSeverity;
use App\Entity\Rule\MailRule;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells an open settings page that a rule run has moved.
 *
 * Deliberately carries no state worth acting on beyond "look again": the run's
 * progress lives on the MailRule row, because the person who started a run
 * will close the tab, reload, or come back on their phone. A push that was the
 * only record would be lost in all three cases.
 *
 * Best-effort — a hub that is down must never fail the run that is doing the
 * actual work.
 */
final readonly class RuleRunNotifier
{
    public function __construct(
        private HubInterface    $hub,
        private LoggerInterface $logger,
    ) {}

    public function publish(MailRule $rule): void
    {
        if (null === $rule->usr) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                topics: [sprintf('mail/user/%d', $rule->usr->id)],
                data: json_encode([
                    'type' => 'rule.run',
                    'ruleId' => $rule->id,
                    'state' => $rule->runState->value,
                    'processed' => $rule->runProcessed,
                ]),
            ));
        } catch (\Throwable $e) {
            // The row has already been written; the page will catch up on its
            // next load either way — which is why this is swallowed.
            //
            // It is no longer swallowed SILENTLY. Catching without binding
            // meant a hub outage and a bug in this method were both perfectly
            // invisible, and the second is not something to find out about
            // from a page that mysteriously stops updating.
            $this->logger->log(
                ThrowableSeverity::level($e, LogLevel::WARNING),
                'RuleRunNotifier: publish failed',
                ['ruleId' => $rule->id, 'error' => $e->getMessage(), 'exception' => $e],
            );
        }
    }
}
