<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Entity\MailRule;
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
        private HubInterface $hub,
    ) {}

    public function publish(MailRule $rule): void
    {
        if (null === $rule->usr) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                topics: [sprintf('mail/user/%d', $rule->usr->getId())],
                data: json_encode([
                    'type' => 'rule.run',
                    'ruleId' => $rule->id,
                    'state' => $rule->runState->value,
                    'processed' => $rule->runProcessed,
                ]),
            ));
        } catch (\Throwable) {
            // The row has already been written; the page will catch up on its
            // next load either way.
        }
    }
}
