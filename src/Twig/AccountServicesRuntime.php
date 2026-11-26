<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Enum\Account\AccountService;
use App\Entity\Mail\Account;
use App\Service\Mail\AccountServiceSwitch;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * The lazy half of AccountServicesExtension: the questions that need the grant.
 *
 * Whether switching a service on needs the provider's consent first is a
 * reading of the recorded grant, and it belongs to AccountServiceSwitch so the
 * switch the page draws and the refusal the controller gives cannot disagree.
 */
final readonly class AccountServicesRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private AccountServiceSwitch $switch,
    ) {}

    public function needsConsent(Account $account, AccountService $service): bool
    {
        return false === $account->isEnabled($service) && true === $this->switch->needsConsent($account, $service);
    }

    /**
     * The account's switched-on services plus one, as the values the OAuth
     * connect route reads: the list a reconnect asks the provider for when it
     * is turning that one on, so the grant that comes back still covers
     * everything the account already did.
     *
     * @return list<string>
     */
    public function with(Account $account, AccountService $service): array
    {
        $services = $account->services;

        if (false === in_array($service, $services, true)) {
            $services[] = $service;
        }

        return array_map(static fn (AccountService $each): string => $each->value, $services);
    }
}
