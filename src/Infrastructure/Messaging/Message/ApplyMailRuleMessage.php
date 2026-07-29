<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Apply one rule to mail that arrived before it existed.
 *
 * Carries the id, not the rule: the envelope may sit in the queue while the
 * rule is edited, and the run should use whatever the rule says when it
 * actually starts.
 */
final readonly class ApplyMailRuleMessage
{
    public function __construct(
        public int $ruleId,
    ) {}
}
