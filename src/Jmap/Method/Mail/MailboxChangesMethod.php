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
 * "Mailbox/changes" (RFC 8621 §2.2). Delegates entirely to the change log:
 * StateManager collapses the log rows since the client's token into the
 * created / updated / destroyed partitions.
 */
final class MailboxChangesMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'Mailbox/changes';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->getId();

        $sinceState = $arguments['sinceState'] ?? null;

        if (false === is_string($sinceState)) {
            throw new MethodException('invalidArguments', '"sinceState" is required.');
        }

        $maxChanges = $arguments['maxChanges'] ?? null;

        if (null !== $maxChanges && false === is_int($maxChanges)) {
            throw new MethodException('invalidArguments', '"maxChanges" must be a number.');
        }

        $changeSet = $this->stateManager->changesSince(
            $accountId,
            JmapObjectType::Mailbox,
            $sinceState,
            $maxChanges,
        );

        $result = $changeSet->toResult((string) $accountId);
        // Mailbox/changes carries this extra member; null = "assume any property
        // may have changed", which is always safe.
        $result['updatedProperties'] = null;

        return $result;
    }
}
