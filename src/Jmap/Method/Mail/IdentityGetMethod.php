<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Account;
use App\Entity\EmailAlias;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;

/**
 * "Identity/get" (RFC 8621 §6.1) — the addresses a client may send from.
 *
 * Backed by Account::getSendableAliases(), the same list the web composer's
 * From dropdown shows, so the two agree by construction. Accounts with no
 * alias rows yet fall back to a single synthetic identity for the account
 * address itself, which is what getSendableAliases() degrades to elsewhere.
 */
final class IdentityGetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
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
        $accountId = $account->getId();

        $requestedIds = $arguments['ids'] ?? null;

        if (null !== $requestedIds && false === is_array($requestedIds)) {
            throw new MethodException('invalidArguments', '"ids" must be an array or null.');
        }

        $identities = $this->identities($account);
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

    /**
     * @return list<array<string,mixed>>
     */
    private function identities(Account $account): array
    {
        $aliases = $account->getSendableAliases();

        if (0 === count($aliases)) {
            return [$this->fallbackIdentity($account)];
        }

        $identities = [];

        foreach ($aliases as $alias) {
            $identities[] = $this->fromAlias($account, $alias);
        }

        return $identities;
    }

    /**
     * @return array<string,mixed>
     */
    private function fromAlias(Account $account, EmailAlias $alias): array
    {
        return [
            'id' => (string) $alias->id,
            'name' => $alias->displayName ?? $account->getName() ?? '',
            'email' => $alias->address,
            'replyTo' => null,
            'bcc' => null,
            'textSignature' => '',
            'htmlSignature' => '',
            // Only aliases the user added by hand are theirs to remove. Ones
            // discovered from the provider come back on the next sync, and the
            // primary address is what the account sends as.
            'mayDelete' => EmailAliasSource::Manual === $alias->source
                && EmailAliasStatus::Primary !== $alias->status,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackIdentity(Account $account): array
    {
        return [
            'id' => (string) $account->getId(),
            'name' => $account->getName() ?? '',
            'email' => (string) $account->getEmail(),
            'replyTo' => null,
            'bcc' => null,
            'textSignature' => '',
            'htmlSignature' => '',
            'mayDelete' => false,
        ];
    }
}
