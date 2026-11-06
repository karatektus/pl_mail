<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;

/**
 * "UnifiedInbox/changes" — plMail extension, `urn:plmail:params:jmap:unifiedinboxes`.
 *
 * What changed in the reader's unified inboxes since a state (#265): made,
 * renamed, given another icon, moved, deleted, or an account moved between
 * two of them. Recorded on every account by UnifiedInboxChanges.
 *
 * An account added or removed changes which accounts the first inbox (or
 * Other) lists without a change here: a client learns of that from the
 * Session, and fetches the inboxes again when its accounts change.
 */
final class UnifiedInboxChangesMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'UnifiedInbox/changes';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $sinceState = $arguments['sinceState'] ?? null;

        if (false === is_string($sinceState)) {
            throw new MethodException('invalidArguments', '"sinceState" is required.');
        }

        $maxChanges = $arguments['maxChanges'] ?? null;

        if (null !== $maxChanges && false === is_int($maxChanges)) {
            throw new MethodException('invalidArguments', '"maxChanges" must be a number.');
        }

        return $this->stateManager
            ->changesSince($account->id, JmapObjectType::UnifiedInbox, $sinceState, $maxChanges)
            ->toResult((string) $account->id);
    }
}
