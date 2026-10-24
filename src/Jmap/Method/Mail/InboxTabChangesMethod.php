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
 * "InboxTab/changes" — plMail extension, `urn:plmail:params:jmap:inboxtabs`.
 *
 * The standard /changes over the change log InboxTabChanges writes. A reorder
 * reports every tab as updated, since every sortOrder may have moved; a
 * switched tab and a renamed one report that tab; a new or deleted tab of the
 * reader's own is created or destroyed.
 */
final class InboxTabChangesMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'InboxTab/changes';
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
            ->changesSince($account->id, JmapObjectType::InboxTab, $sinceState, $maxChanges)
            ->toResult((string) $account->id);
    }
}
