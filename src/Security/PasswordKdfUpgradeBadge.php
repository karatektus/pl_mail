<?php

declare(strict_types=1);

namespace App\Security;

use SensitiveParameter;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/**
 * A sign-in that carried the password itself one last time, alongside the
 * auth hash the browser stretched from it, so the stored hash can move over.
 *
 * Added by LoginFormAuthenticator only when the form sent both; acted on, and
 * resolved, by PasswordKdfUpgradeListener once the password has been checked.
 * The email is the one the browser asked the prelogin about, because that is
 * what the placeholder salt the auth hash was made with was computed from.
 */
final class PasswordKdfUpgradeBadge implements BadgeInterface
{
    private bool $resolved = false;

    public function __construct(
        #[SensitiveParameter]
        public readonly string $authHash,
        public readonly string $email,
    ) {
    }

    public function markResolved(): void
    {
        $this->resolved = true;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
