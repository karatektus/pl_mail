<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Enum\Account\AccountService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the account settings need to draw the three service switches (#575).
 *
 * The functions that read the grant live in AccountServicesRuntime, and that
 * split is the point of this class. An extension is built with the Twig
 * environment, on every request that renders anything; AccountServiceSwitch's
 * dependencies reach as far as the integration HTTP client, so building it
 * here constructed all of that on every page — and made the client
 * impossible for a test to replace afterwards, which is how this was found. A
 * runtime is built the first time one of its functions is called.
 */
final class AccountServicesExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('account_services', static fn (): array => AccountService::cases()),
            new TwigFunction('account_service_needs_consent', [AccountServicesRuntime::class, 'needsConsent']),
            new TwigFunction('account_services_with', [AccountServicesRuntime::class, 'with']),
        ];
    }
}
