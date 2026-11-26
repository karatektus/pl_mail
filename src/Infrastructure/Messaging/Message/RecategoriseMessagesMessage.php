<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Sort these messages again, after the filter that held them in a built-in
 * tab was deleted or changed (#762). Dispatched by RuleBuiltinTabReleaser;
 * RecategoriseMessagesHandler does the work.
 *
 * The ids travel because the release already cleared the column that said
 * which messages they were. At most RuleBuiltinTabReleaser::BATCH of them per
 * envelope, so one broad filter makes several small jobs, not one huge one.
 */
final readonly class RecategoriseMessagesMessage
{
    /**
     * @param list<int> $messageIds
     */
    public function __construct(
        public int   $userId,
        public array $messageIds,
    ) {}
}
