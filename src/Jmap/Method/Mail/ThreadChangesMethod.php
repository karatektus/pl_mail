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
 * "Thread/changes" (RFC 8621 §3.2). Identical in shape to Email/changes — the
 * change log does the work.
 *
 * NOTE: nothing records JmapObjectType::Thread yet, so this correctly reports
 * "no changes" until the syncers start recording thread mutations. That is a
 * truthful answer (state "0" never advances), not a silent failure — but a
 * client relying on Thread/changes alone will not see new threads. Clients
 * pair it with Email/changes in practice, which is wired.
 */
final class ThreadChangesMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'Thread/changes';
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
            JmapObjectType::Thread,
            $sinceState,
            $maxChanges,
        );

        return $changeSet->toResult((string) $accountId);
    }
}
