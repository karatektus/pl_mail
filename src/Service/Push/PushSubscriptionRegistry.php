<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Domain\Enum\PushHealth;
use App\Domain\Interface\PushSubscriptionManagerInterface;
use App\Entity\Account;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Picks the push manager for an account, or null when the provider has none.
 *
 * Returning null rather than throwing is deliberate: IMAP accounts legitimately
 * have no push, and every caller (settings UI, renewal command, OAuth callback,
 * account deletion) wants to skip quietly rather than handle an exception.
 */
final readonly class PushSubscriptionRegistry
{
    /**
     * @param iterable<PushSubscriptionManagerInterface> $managers
     */
    public function __construct(
        #[AutowireIterator('app.push_subscription_manager')]
        private iterable $managers,
    ) {}

    public function resolve(Account $account): ?PushSubscriptionManagerInterface
    {
        foreach ($this->managers as $manager) {
            if (true === $manager->supports($account)) {
                return $manager;
            }
        }

        return null;
    }

    public function supportsPush(Account $account): bool
    {
        return null !== $this->resolve($account);
    }

    /**
     * Health for any account, including ones with no push support at all.
     */
    public function health(Account $account): PushHealth
    {
        $manager = $this->resolve($account);

        if (null === $manager) {
            return PushHealth::Inactive;
        }

        return $manager->health($account);
    }
}
