<?php

declare(strict_types=1);

namespace App\Domain\Enum\Onboarding;

/**
 * The stops on the setup wizard, in the order they are shown.
 *
 * `cases()` *is* the order — there is no separate ordering table to keep in
 * step, and the order is load-bearing twice over. Administrator plumbing comes
 * first so an admin who enters Google credentials here can use OAuth in the
 * account step; and integrations come before the profile so the avatar can be
 * picked out of a service the user has just connected.
 *
 * Which of these a given user actually sees is not decided here. Each step has
 * a handler that answers whether it applies (App\Service\Onboarding\Step), so a
 * plain user never meets the admin stops and nobody is shown a step for
 * something that is already set up.
 *
 * The value is the URL segment, so renaming one changes a bookmarkable address
 * and orphans any half-finished wizard resuming at it.
 */
enum OnboardingStep: string
{
    case AdminMailCredentials = 'admin-mail';
    case AdminIntegrationCredentials = 'admin-integrations';
    case Account = 'account';
    case Integrations = 'integrations';
    case Profile = 'profile';
    case Appearance = 'appearance';

    /** Translation key for the step's title; the lead is `.lead` beside it. */
    public function transKey(): string
    {
        return 'onboarding.step.'.$this->value;
    }

    /** FontAwesome class for the step's pill in the progress rail. */
    public function icon(): string
    {
        return match ($this) {
            self::AdminMailCredentials        => 'fa-solid fa-key',
            self::AdminIntegrationCredentials => 'fa-solid fa-plug-circle-bolt',
            self::Account                     => 'fa-solid fa-envelope',
            self::Profile                     => 'fa-solid fa-user',
            self::Appearance                  => 'fa-solid fa-palette',
            self::Integrations                => 'fa-solid fa-cloud',
        };
    }

    /**
     * Whether the step configures the whole install rather than one person's
     * mailbox. Only ROLE_ADMIN is offered these.
     */
    public function requiresAdmin(): bool
    {
        return match ($this) {
            self::AdminMailCredentials, self::AdminIntegrationCredentials => true,
            default                                                       => false,
        };
    }
}
