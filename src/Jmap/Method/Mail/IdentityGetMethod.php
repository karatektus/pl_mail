<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Mail\IdentityResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;

/**
 * "Identity/get" (RFC 8621 §6.1) — the addresses a client may send from.
 *
 * The list itself is IdentityResolver's, not this method's, because
 * "EmailSubmission/set" has to read the same one back: an identityId is only
 * meaningful if the method that publishes it and the method that spends it
 * agree on what exists. See IdentityResolver for why that is one class.
 */
final class IdentityGetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly IdentityResolver $identityResolver,
        private readonly StateManager $stateManager,
    ) {
    }

    public function name(): string
    {
        return 'Identity/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->id;

        $requestedIds = $arguments['ids'] ?? null;

        if (null !== $requestedIds && false === is_array($requestedIds)) {
            throw new MethodException('invalidArguments', '"ids" must be an array or null.');
        }

        $identities = $this->identityResolver->identities($account);
        $list = [];
        $found = [];

        foreach ($identities as $identity) {
            if (null !== $requestedIds && false === in_array($identity['id'], array_map('strval', $requestedIds), true)) {
                continue;
            }

            $found[] = $identity['id'];
            $list[] = $identity;
        }

        $notFound = [];

        if (null !== $requestedIds) {
            $notFound = array_values(array_diff(array_map('strval', $requestedIds), $found));
        }

        return [
            'accountId' => (string) $accountId,
            'state' => $this->stateManager->stateFor($accountId, JmapObjectType::Identity),
            'list' => $list,
            'notFound' => $notFound,
        ];
    }
}
