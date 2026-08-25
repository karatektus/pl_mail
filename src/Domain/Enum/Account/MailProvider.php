<?php

declare(strict_types=1);

namespace App\Domain\Enum\Account;

/**
 * A cloud mail provider we connect to over OAuth2.
 *
 * Holds only the *non-flow* provider differences (scopes, authorization-url
 * options). The OAuth flow itself is provider-agnostic and lives in the OAuth
 * service layer.
 *
 * Microsoft note: we deliberately do NOT request IMAP/SMTP scopes.
 * Exchange Online classifies IMAP/POP/SMTP as legacy-authentication clients
 * in Entra Conditional Access, so IMAP+XOAUTH2 is blocked outright in any
 * tenant running Security Defaults — which is the default for new tenants.
 * Microsoft Graph is the only path that works everywhere and is the only one
 * Microsoft is investing in (EWS is disabled from 2026-10-01 and removed
 * 2027-04-01). Microsoft accounts therefore never touch the IMAP stack.
 */
enum MailProvider: string
{
    case Google    = 'google';
    case Microsoft = 'microsoft';

    /**
     * The provider's own name for itself, for a chip or a button.
     *
     * Not translated: these are brand names, and "Gmail" is Gmail in every
     * locale. The surrounding prose that explains what to do with them is
     * translated, and lives in the message catalogues.
     */
    public function label(): string
    {
        return match ($this) {
            self::Google    => 'Gmail',
            self::Microsoft => 'Microsoft',
        };
    }

    /** FontAwesome class for the provider's mark, as Integration\Provider does. */
    public function icon(): string
    {
        return match ($this) {
            self::Google    => 'fa-brands fa-google',
            self::Microsoft => 'fa-brands fa-microsoft',
        };
    }

    /**
     * OAuth scopes requested at consent time.
     *
     * Calendar is in here rather than behind a second connection, and that is a
     * product decision as much as a technical one: an administrator should have
     * to tick extra permission boxes in Google Cloud or Azure and nothing more
     * — no second OAuth app, no second integration to configure in plMail, no
     * second consent screen for the user. One grant covers the mailbox and the
     * calendars in the same account.
     *
     * The cost is that the consent screen asks for more up front. Google's lets
     * a user untick an individual scope, so a token can come back without
     * calendar access; that is discovered when a calendar call is refused and
     * reported there, rather than tracked speculatively at grant time.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            MailProvider::Google => [
                'https://mail.google.com/',
                // Read-write, not calendar.readonly: subscribing is only half
                // the feature — an event edited here is written back, which
                // means creating, updating and deleting on the remote.
                'https://www.googleapis.com/auth/calendar',
                'openid',
                'email',
            ],
            MailProvider::Microsoft => [
                'offline_access',
                'openid',
                'email',
                'profile',
                'https://graph.microsoft.com/User.Read',
                'https://graph.microsoft.com/Mail.ReadWrite',
                'https://graph.microsoft.com/Mail.Send',
                // Master categories live under the Outlook user-settings
                // resource, NOT under Mail.* — /me/outlook/masterCategories
                // returns ErrorAccessDenied without this. ReadWrite rather than
                // Read because GraphCategorySyncer and ApplyGraphChangesHandler
                // both create category definitions.
                'https://graph.microsoft.com/MailboxSettings.ReadWrite',
                // Same grant as the mail scopes above — see the note on this
                // method. Microsoft consents to the requested set as a whole,
                // so unlike Google there is no partial grant to handle.
                'https://graph.microsoft.com/Calendars.ReadWrite',
            ],
        };
    }

    /**
     * The scopes that buy calendar access, out of the set above.
     *
     * Named separately so the failure path can say which permission is missing
     * — "reconnect and allow calendar access" is actionable, "403" is not — and
     * so the admin help text can list exactly what to tick without repeating
     * the strings a third time.
     *
     * @return list<string>
     */
    /**
     * What a provider calls it when the token is valid and too narrow.
     *
     * Google sends two spellings depending on which error envelope answers —
     * see GoogleCalendarApiClient::SCOPE_REASONS, which lists the same two for
     * the same reason. Microsoft's Graph uses ErrorAccessDenied for this and
     * for several unrelated things, so it is deliberately NOT here: a code that
     * means four different conditions cannot be used as evidence of one.
     *
     * @var list<string>
     */
    /**
     * The handshake scopes, which are requested and not reported back.
     *
     * Not permissions over anything: they identify the user and ask for a
     * refresh token. A provider that answers without them has not withheld
     * anything, and treating their absence as a shortfall is what made the
     * "not given full access" card unclearable.
     *
     * @var list<string>
     */
    private const array SIGN_IN_SCOPES = [
        'openid',
        'email',
        'profile',
        'offline_access',
    ];

    private const array SCOPE_REFUSAL_CODES = [
        'insufficientPermissions',
        'ACCESS_TOKEN_SCOPE_INSUFFICIENT',
    ];

    public function calendarScopes(): array
    {
        return match ($this) {
            MailProvider::Google    => ['https://www.googleapis.com/auth/calendar'],
            MailProvider::Microsoft => ['https://graph.microsoft.com/Calendars.ReadWrite'],
        };
    }

    /**
     * Whether a grant we were actually given includes calendar access.
     *
     * The question exists because the answer is routinely no. Google's consent
     * screen offers sensitive scopes as individual tick boxes, so a user can
     * grant mail, decline calendar, and come back with a token that works
     * perfectly for everything except the thing they declined — nothing about
     * the handshake fails. Microsoft consents to the requested set as a whole,
     * but an admin can restrict what a tenant will hand out, and the result
     * arrives the same way: a narrower `scope` in the token response.
     *
     * OAuth 2.0 requires the authorization server to return the granted `scope`
     * whenever it differs from the request, and both providers return it every
     * time. Comparing it against what was asked for is the only way to learn
     * this at CONNECT time rather than days later, when a sync 403s and the
     * consent screen is a distant memory.
     *
     * @param string|null $granted the `scope` value from the token response
     *
     * @return bool|null null when there is nothing to judge — no scope string
     *                   came back, so the grant matched the request
     */
    public function grantsCalendarAccess(?string $granted): ?bool
    {
        $missing = $this->missingScopes($granted);

        if (null === $missing) {
            return null;
        }

        return [] === array_intersect($missing, $this->calendarScopes());
    }

    /**
     * The scopes that grant access to DATA, as opposed to signing in.
     *
     * `openid`, `email`, `profile` and `offline_access` are asked for in the
     * authorization request and are routinely absent from the granted `scope`
     * that comes back — the providers treat them as part of the handshake
     * rather than as permissions to enumerate. Comparing them reported four
     * missing scopes on a grant that was completely fine, which put a card on
     * the health page that reconnecting could never clear: every reconnect
     * recorded the same answer and the same four "missing" entries.
     *
     * So the comparison is over what actually buys access to somebody's mail
     * and calendar. Those are echoed, by both providers, every time.
     *
     * @return list<string>
     */
    public function capabilityScopes(): array
    {
        return array_values(array_filter(
            $this->scopes(),
            static fn (string $scope): bool => false === in_array($scope, self::SIGN_IN_SCOPES, true),
        ));
    }

    /**
     * Everything that was asked for and not given.
     *
     * Calendar is the case people notice, because a calendar that stops
     * updating is visible. It is not the worst one. A Google account granted
     * read-only mail accepts every sign-in, syncs perfectly, and then refuses
     * `messages.batchModify` with 403 insufficientPermissions — so marking five
     * thousand conversations read succeeds locally, never reaches Gmail, and is
     * quietly undone by the next sync. Nothing about that is visible anywhere
     * unless the granted set is compared against the requested one.
     *
     * @param string|null $granted the `scope` value from the token response
     *
     * @return list<string>|null the requested scopes that were withheld, or
     *                           null when there is nothing to judge
     */
    public function missingScopes(?string $granted): ?array
    {
        if (null === $granted || '' === trim($granted)) {
            return null;
        }

        $held    = array_map($this->normaliseScope(...), explode(' ', trim($granted)));
        $missing = [];

        foreach ($this->capabilityScopes() as $wanted) {
            if (false === in_array($this->normaliseScope($wanted), $held, true)) {
                $missing[] = $wanted;
            }
        }

        return $missing;
    }

    /**
     * Whether a stored error message is the provider saying our grant is short.
     *
     * The direct evidence — the granted `scope` recorded at connect time — is
     * null for every account connected before it was recorded, and those are
     * precisely the accounts already suffering from a partial grant. Waiting
     * for the next token refresh to backfill it leaves somebody staring at
     * three broken calendars and no explanation, which is exactly what
     * happened.
     *
     * So the indirect evidence counts too, and it is already in the database:
     * a calendar that failed for this reason stored the provider's own words,
     * and those words contain the reason CODE rather than only prose.
     *
     * Matched on the codes and not on the sentence around them. `insufficientPermissions`
     * and `ACCESS_TOKEN_SCOPE_INSUFFICIENT` are Google's identifiers — the two
     * spellings its two error envelopes use, both listed in
     * GoogleCalendarApiClient — and identifiers are stable in a way the
     * sentence is not. Matching the sentence would break the first time it was
     * reworded or translated.
     */
    public function looksLikeScopeRefusal(?string $error): bool
    {
        if (null === $error || '' === trim($error)) {
            return false;
        }

        $haystack = strtolower($error);

        foreach (self::SCOPE_REFUSAL_CODES as $code) {
            if (true === str_contains($haystack, strtolower($code))) {
                return true;
            }
        }

        return false;
    }

    /**
     * One spelling of a scope, so two spellings of the same one compare equal.
     *
     * Necessary rather than fastidious: Microsoft accepts
     * `https://graph.microsoft.com/Calendars.ReadWrite` in the request and
     * answers with a bare `Calendars.ReadWrite`, in whatever case it feels
     * like. A literal comparison would report every Microsoft account as
     * missing calendar access.
     *
     * The host prefix is stripped and nothing else. Trimming to the last path
     * segment would be wrong for Google, where `auth/calendar` and
     * `auth/calendar.events` are different permissions that must not collapse
     * into one.
     */
    private function normaliseScope(string $scope): string
    {
        $bare = preg_replace('#^https?://[^/]+/#i', '', trim($scope)) ?? $scope;

        return strtolower(rtrim($bare, '/'));
    }

    /**
     * Extra parameters appended to the authorization URL.
     *
     * Google needs prompt=consent (plus accessType=offline, set on the league
     * provider) to reliably return a refresh token. Microsoft signals the same
     * intent via the offline_access scope, so it needs nothing here.
     *
     * @return array<string, string>
     */
    public function authorizationUrlOptions(): array
    {
        return match ($this) {
            MailProvider::Google    => ['prompt' => 'consent'],
            MailProvider::Microsoft => [],
        };
    }

    /**
     * Whether this provider is synced over IMAP at all.
     *
     * Both current providers are API-synced; the method exists so the IMAP
     * syncer can ask the question instead of hard-coding provider checks.
     */
    public function usesImap(): bool
    {
        return false;
    }

    public function imapHost(): ?string
    {
        return match ($this) {
            MailProvider::Google    => 'imap.gmail.com',
            MailProvider::Microsoft => null,
        };
    }

    public function imapPort(): ?int
    {
        return match ($this) {
            MailProvider::Google    => 993,
            MailProvider::Microsoft => null,
        };
    }

    public function imapEncryption(): ?string
    {
        return match ($this) {
            MailProvider::Google    => 'ssl',
            MailProvider::Microsoft => null,
        };
    }
}
