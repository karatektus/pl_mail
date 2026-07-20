<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Domain\Enum\MailProvider;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Google;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds a configured league OAuth2 provider for a given MailProvider.
 *
 * This is the only place that knows about concrete league provider classes.
 * Adding Microsoft later = one more branch here + its provider package.
 */
class OAuthProviderFactory
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $googleClientId,
        private readonly string $googleClientSecret,
    ) {
    }

    public function create(MailProvider $provider): AbstractProvider
    {
        dump($this->googleClientId);
        $redirectUri = $this->urlGenerator->generate(
            'app_oauth_callback',
            ['provider' => $provider->value],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        if (MailProvider::Google === $provider) {
            return new Google([
                'clientId'     => $this->googleClientId,
                'clientSecret' => $this->googleClientSecret,
                'redirectUri'  => $redirectUri,
                'accessType'   => 'offline',
            ]);
        }

        throw new \RuntimeException(sprintf(
            'OAuth provider "%s" is not yet implemented.',
            $provider->value,
        ));
    }
}
