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
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            MailProvider::Google => [
                'https://mail.google.com/',
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
            ],
        };
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
