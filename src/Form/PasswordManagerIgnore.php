<?php

declare(strict_types=1);

namespace App\Form;

/**
 * Attributes that tell password-manager extensions to leave a field alone.
 *
 * Server credentials, API keys and address fields are not website logins:
 * extensions that classify them as one draw their overlay icons into them —
 * and Bitwarden's injected chrome outlives a Turbo frame swap, leaving
 * orphaned icons and underlines floating over whatever renders next.
 * `autocomplete="off"` is ignored by all of them; these vendor attributes are
 * the documented opt-out (Bitwarden, 1Password, LastPass, Dashlane).
 *
 * Deliberately NOT applied to real logins — the sign-in form and the first
 * admin's password — where offering to save is exactly what the user wants.
 */
final class PasswordManagerIgnore
{
    public const array ATTR = [
        'data-bwignore'  => 'true',
        'data-1p-ignore' => 'true',
        'data-lpignore'  => 'true',
        'data-form-type' => 'other',
    ];
}
