<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * A cloud mail provider we connect to over OAuth2.
 *
 * Holds only the *non-flow* provider differences (scopes, mail hosts,
 * authorization-url options). The OAuth flow itself is provider-agnostic and
 * lives in the OAuth service layer.
 */
enum MailProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';

    /**
     * OAuth scopes requested at consent time.
     *
     * @return string[]
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
                'https://outlook.office.com/IMAP.AccessAsUser.All',
                'https://outlook.office.com/SMTP.Send',
                'openid',
                'email',
            ],
        };
    }

    /**
     * Extra parameters appended to the authorization URL.
     *
     * Google needs access_type=offline + prompt=consent to reliably return a
     * refresh token. Microsoft signals the same intent via the offline_access
     * scope, so it needs nothing here.
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

    public function imapHost(): string
    {
        return match ($this) {
            MailProvider::Google    => 'imap.gmail.com',
            MailProvider::Microsoft => 'outlook.office365.com',
        };
    }

    public function imapPort(): int
    {
        return 993;
    }

    public function imapEncryption(): string
    {
        return 'ssl';
    }
}
