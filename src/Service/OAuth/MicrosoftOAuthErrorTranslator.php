<?php

declare(strict_types=1);

namespace App\Service\OAuth;

/**
 * Turns Entra's AADSTS error codes into something a user can act on.
 *
 * Entra returns everything as a generic `access_denied` or `invalid_grant`
 * with the real cause buried in a prose error_description. Surfacing that raw
 * string is useless — it is several sentences long, contains a correlation ID
 * and a timestamp, and tells the user nothing they can do. Matching the code
 * lets us say the one thing that matters, which for the most common failure is
 * "ask your administrator".
 *
 * Returns a translation key; unknown codes fall back to the generic message so
 * a new AADSTS code never produces a blank error page.
 */
final readonly class MicrosoftOAuthErrorTranslator
{
    private const string FALLBACK = 'oauth.error.generic';

    private const array CODE_MAP = [
        // The tenant requires an administrator to consent on users' behalf.
        // By far the most common failure for a third-party mail client.
        'AADSTS65001' => 'oauth.error.microsoft.admin_consent_required',
        'AADSTS90094' => 'oauth.error.microsoft.admin_consent_required',
        // The app registration's supported-account-types does not match the
        // configured MICROSOFT_OAUTH_TENANT. An operator error, not a user one.
        'AADSTS50194' => 'oauth.error.microsoft.tenant_mismatch',
        'AADSTS50020' => 'oauth.error.microsoft.tenant_mismatch',
        // The application is not present in the signing-in tenant at all.
        'AADSTS700016' => 'oauth.error.microsoft.app_not_found',
        // A Conditional Access policy blocked the sign-in.
        'AADSTS53003' => 'oauth.error.microsoft.blocked_by_policy',
        // The user abandoned the consent screen.
        'AADSTS65004' => 'oauth.error.microsoft.consent_declined',
        // Client secret expired or wrong — operator error.
        'AADSTS7000215' => 'oauth.error.microsoft.bad_credentials',
        'AADSTS7000222' => 'oauth.error.microsoft.bad_credentials',
    ];

    /**
     * @return array{key: string, code: string|null, adminActionable: bool}
     */
    public function translate(?string $rawError): array
    {
        $code = $this->extractCode($rawError);

        if (null === $code) {
            return [
                'key'             => self::FALLBACK,
                'code'            => null,
                'adminActionable' => false,
            ];
        }

        $key = self::CODE_MAP[$code] ?? self::FALLBACK;

        return [
            'key'             => $key,
            'code'            => $code,
            'adminActionable' => 'oauth.error.microsoft.admin_consent_required' === $key,
        ];
    }

    public function isAdminConsentRequired(?string $rawError): bool
    {
        return true === $this->translate($rawError)['adminActionable'];
    }

    private function extractCode(?string $rawError): ?string
    {
        if (null === $rawError || '' === $rawError) {
            return null;
        }

        if (1 !== preg_match('/\b(AADSTS\d+)\b/', $rawError, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
