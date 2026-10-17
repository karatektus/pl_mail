<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Oidc\OidcSettings;

/**
 * Whether the login page offers single sign-on, and what its button says.
 *
 * A global, like `demo`, which the same page already asks the same kind of
 * question of — one way for the login template to learn what this install
 * offers, rather than one global and one controller variable.
 */
final readonly class OidcGlobal
{
    public function __construct(
        private OidcSettings $settings,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->current()->isEnabled();
    }

    /**
     * Whether the login page shows the password form. False only on an install
     * whose administrator made single sign-on the only way in.
     */
    public function passwordLoginAllowed(): bool
    {
        return $this->settings->current()->allowsPasswordLogin();
    }

    /**
     * The provider's name as the operator wants it on the button, or null for
     * the generic wording.
     */
    public function label(): ?string
    {
        $label = trim($this->settings->current()->buttonLabel);

        return '' === $label ? null : $label;
    }
}
