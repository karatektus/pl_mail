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
 * "EmailSubmission/changes" (RFC 8621 §7.3).
 *
 * Nothing is computed here: this reads the change log, and the three writes
 * that put anything in it are the three transitions EmailSubmission/get can
 * report. A submit records `created`; accepting a cancel records `updated`
 * (EmailSubmissionSetMethod); and the send itself records `updated` when the
 * mail finally leaves (MessageSendService, for submitted mail only).
 *
 * The last two were added with the submission becoming gettable while pending.
 * While a held submission answered notFound there was deliberately nothing to
 * announce — a change entry would have woken every client to re-fetch an id
 * that was not there — and once it answers `pending`, `canceled` and `final` in
 * turn, a client that heard only about the first would sit on a schedule for
 * mail that has already gone or been called off.
 */
final class EmailSubmissionChangesMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'EmailSubmission/changes';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->id;

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
            JmapObjectType::EmailSubmission,
            $sinceState,
            $maxChanges,
        );

        return $changeSet->toResult((string) $accountId);
    }
}
