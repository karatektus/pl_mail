<?php

declare(strict_types=1);

namespace App\Jmap\Method\ContactCard;

use App\Domain\Exception\ContactStateTokenException;
use App\Jmap\Account\ContactAccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Contacts\Change\ContactChangeReader;

/**
 * "ContactCard/changes" (RFC 9610 §3.2), from contact_change_log. A contact
 * binned reads as destroyed, one restored as created; see
 * ContactChangeLogListener.
 */
final class ContactCardChangesMethod implements JmapMethod
{
    public function __construct(
        private readonly ContactAccountResolver $accounts,
        private readonly ContactChangeReader $changes,
    ) {
    }

    public function name(): string
    {
        return 'ContactCard/changes';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accounts->resolve($context->user, $arguments['accountId'] ?? null);

        try {
            $delta = $this->changes->cardsSince((int) $context->user->id, ChangesArguments::sinceState($arguments), ChangesArguments::maxChanges($arguments));
        } catch (ContactStateTokenException $e) {
            throw new MethodException('cannotCalculateChanges', $e->getMessage());
        }

        return ChangesArguments::response((string) $account->id, $delta);
    }
}
