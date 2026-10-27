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
 * "AddressBook/changes" (RFC 9610 §2.2), from contact_change_log. plMail's own
 * book never changes; a mirrored one is created, renamed or removed.
 */
final class AddressBookChangesMethod implements JmapMethod
{
    public function __construct(
        private readonly ContactAccountResolver $accounts,
        private readonly ContactChangeReader $changes,
    ) {
    }

    public function name(): string
    {
        return 'AddressBook/changes';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accounts->resolve($context->user, $arguments['accountId'] ?? null);

        try {
            $delta = $this->changes->booksSince((int) $context->user->id, ChangesArguments::sinceState($arguments), ChangesArguments::maxChanges($arguments));
        } catch (ContactStateTokenException $e) {
            throw new MethodException('cannotCalculateChanges', $e->getMessage());
        }

        return ChangesArguments::response((string) $account->id, $delta) + ['updatedProperties' => null];
    }
}
