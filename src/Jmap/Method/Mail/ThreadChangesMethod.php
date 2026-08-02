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
 * Thread mutations are recorded: every path that touches an Email calls
 * StateManager::recordThreadsTouched() with the thread ids it affected — the
 * three sync paths through PostIngestPipeline, Email/set, Thread/set's snooze,
 * the web's thread actions and the composer. So this method does report
 * changes, and a client may rely on it.
 *
 * What it never reports is "created". recordThreadsTouched() logs everything as
 * updated on purpose (StateManager.php:56-70): a Thread has no mutations of its
 * own — it changes because one of its Emails did — and telling a brand-new
 * thread from a grown one would mean asking whether every one of its messages
 * is also new. RFC 8620 §5.2 already requires a client to fetch an id in
 * "updated" that it does not yet hold, so the distinction buys nothing.
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
