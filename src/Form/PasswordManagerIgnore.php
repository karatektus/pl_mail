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

    /**
     * For the secret INPUT itself, not the fields standing around it.
     *
     * Everything above speaks to extensions. This speaks to the browser, which
     * is a separate problem with a separate opt-out: Chrome's built-in autofill
     * has no vendor attribute to respect and ignores `autocomplete="off"` on a
     * password field. It sees `type="password"` on the same origin as the login
     * form, offers the password saved for this site, and fills the nearest
     * text-like field above it with the matching username.
     *
     * Reported from the admin AI pane: clicking "Model host" pulled in the
     * operator's own mail address and the token field took their mail password
     * — a login credential one submit away from being stored as an API token
     * and sent to whatever host that field names.
     *
     * `new-password` is the documented way to say "this is not the credential
     * you have saved for this site". It suppresses the offer, and with it the
     * "update your password?" prompt that a filled-then-submitted form raises.
     *
     * Deliberately NOT spread onto the text fields nearby. On a non-password
     * input `new-password` invites Chrome to offer to GENERATE one, which is a
     * worse interruption than the autofill it would have prevented; those keep
     * plain `autocomplete="off"`, which is enough once the password field next
     * to them has stopped advertising itself as a login.
     */
    public const array SECRET = [
        ...self::ATTR,
        'autocomplete' => 'new-password',
    ];
}
