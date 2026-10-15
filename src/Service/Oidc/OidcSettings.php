<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Entity\Oidc\OidcConfig;
use App\Repository\Oidc\OidcConfigRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which single sign-on configuration is in force: what an administrator saved
 * under Admin → SSO, or the environment when they saved nothing whole.
 *
 * The database first and the environment second, the order OAuthProviderFactory
 * uses for the mail registrations and for its reason: somebody editing the
 * value in the UI expects it to take effect, and an install that has only ever
 * used the OIDC_* variables keeps working with no migration.
 *
 * One source at a time, never a field from each. Issuer, client id and secret
 * are one provider's registration, and an issuer from the form paired with a
 * secret from a compose file would be a configuration nobody wrote. So a row
 * that is whole is the configuration, options included, and a row that is not
 * is ignored altogether.
 *
 * Asked per call rather than resolved once: the web server is a long-lived
 * worker, and a value read at construction would outlive the save that changed
 * it. It is one indexed read of a one-row table, on the login page and during a
 * sign-in only.
 */
final readonly class OidcSettings
{
    public const string SOURCE_ADMIN = 'admin';
    public const string SOURCE_ENVIRONMENT = 'environment';
    public const string SOURCE_NONE = 'none';

    private const string DEFAULT_SCOPES = 'openid email profile';

    public function __construct(
        private OidcConfigRepository $configs,
        #[Autowire('%env(OIDC_ISSUER)%')]
        private string               $issuer,
        #[Autowire('%env(OIDC_CLIENT_ID)%')]
        private string               $clientId,
        #[Autowire('%env(OIDC_CLIENT_SECRET)%')]
        #[\SensitiveParameter]
        private string               $clientSecret,
        #[Autowire('%env(OIDC_BUTTON_LABEL)%')]
        private string               $buttonLabel,
        #[Autowire('%env(bool:OIDC_AUTO_CREATE)%')]
        private bool                 $autoCreate,
        #[Autowire('%env(OIDC_REQUIRE_VERIFIED_EMAIL)%')]
        private string               $requireVerifiedEmail,
        #[Autowire('%env(OIDC_SCOPES)%')]
        private string               $scopes,
    ) {
    }

    public function current(): OidcConfiguration
    {
        $stored = $this->configs->current();

        return null !== $stored && true === $stored->isComplete()
            ? $this->fromRow($stored)
            : $this->fromEnvironment();
    }

    /**
     * Where current() gets its answer, for the admin page to say so. "From the
     * environment" is a state worth naming: sign-in may well be working off a
     * compose file with nothing in the table.
     */
    public function source(): string
    {
        $stored = $this->configs->current();

        if (null !== $stored && true === $stored->isComplete()) {
            return self::SOURCE_ADMIN;
        }

        return true === $this->fromEnvironment()->isEnabled() ? self::SOURCE_ENVIRONMENT : self::SOURCE_NONE;
    }

    private function fromRow(OidcConfig $stored): OidcConfiguration
    {
        return new OidcConfiguration(
            trim((string) $stored->issuer),
            trim((string) $stored->clientId),
            (string) $stored->clientSecret,
            trim((string) $stored->buttonLabel),
            $stored->autoCreate,
            $stored->requireVerifiedEmail,
            '' === trim((string) $stored->scopes) ? self::DEFAULT_SCOPES : (string) $stored->scopes,
        );
    }

    private function fromEnvironment(): OidcConfiguration
    {
        return new OidcConfiguration(
            $this->issuer,
            $this->clientId,
            $this->clientSecret,
            $this->buttonLabel,
            $this->autoCreate,
            // Read as a string and switched off only by a value that says so.
            // The `bool:` processor calls everything it does not recognise
            // false, the empty string included — and a compose file that passes
            // the variable through blank, or a typo, would then turn off the
            // one check that stops an unverified address claiming an account.
            // The safe answer has to be the one a mistake lands on.
            false === in_array(strtolower(trim($this->requireVerifiedEmail)), ['false', '0', 'no', 'off'], true),
            $this->scopes,
        );
    }
}
