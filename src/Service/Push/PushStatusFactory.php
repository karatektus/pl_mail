<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Domain\DTO\PushStatus;
use App\Entity\Account;
use Twig\Attribute\AsTwigFunction;

/**
 * Single place that assembles a PushStatus, used from PHP and from Twig.
 *
 * Exposed to templates as push_status(account) so the toggle partial is
 * self-sufficient — any template can include it with just the account, and
 * there is exactly one implementation of "what is this account's push state".
 */
final readonly class PushStatusFactory
{
    public function __construct(
        private PushSubscriptionRegistry $registry,
    ) {}

    #[AsTwigFunction('push_status')]
    public function build(Account $account): PushStatus
    {
        $manager = $this->registry->resolve($account);

        if (null === $manager) {
            return PushStatus::unsupported();
        }

        return new PushStatus(
            supported:  true,
            enabled:    $account->isPushEnabled(),
            configured: $manager->isConfigured(),
            health:     $manager->health($account),
            expiresAt:  $manager->expiresAt($account),
        );
    }
}
