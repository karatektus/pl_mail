<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Domain\Interface\VerifiableDriverInterface;
use App\Entity\Integration\Integration;

/**
 * Finds the driver for a provider.
 *
 * Same shape as MailSenderRegistry: drivers are injected as a tagged iterator
 * and the first that claims the provider wins. A provider with no driver is a
 * stub, and asking for one is a programming error rather than a user error —
 * every UI path checks Provider::isImplemented() before it gets here.
 *
 * The iterator is of the *broader* interface, because not every connection
 * holds files: a calendar driver can prove its credentials and nothing else.
 * `for()` therefore narrows, and says so when the narrowing fails — asking a
 * CalDAV server to list a folder is a bug here, not a message for a user.
 */
final readonly class IntegrationDriverRegistry
{
    /**
     * @param iterable<VerifiableDriverInterface> $drivers
     */
    public function __construct(
        private iterable $drivers,
    ) {
    }

    /**
     * @throws IntegrationException
     */
    public function for(Provider $provider): IntegrationDriverInterface
    {
        $driver = $this->verifiableFor($provider);

        if (false === $driver instanceof IntegrationDriverInterface) {
            throw new IntegrationException(sprintf(
                '%s holds no files.',
                $provider->label(),
            ));
        }

        return $driver;
    }

    /**
     * The driver for anything that can be connected, files or not — which is
     * what the connect, test and onboarding paths want, since all three ask
     * only whether the credentials work.
     *
     * @throws IntegrationException
     */
    public function verifiableFor(Provider $provider): VerifiableDriverInterface
    {
        foreach ($this->drivers as $driver) {
            if (true === $driver->supports($provider)) {
                return $driver;
            }
        }

        throw new IntegrationException(sprintf(
            '%s is not available yet.',
            $provider->label(),
        ));
    }

    /**
     * @throws IntegrationException
     */
    public function forIntegration(Integration $integration): IntegrationDriverInterface
    {
        return $this->for($integration->provider);
    }

    /**
     * @throws IntegrationException
     */
    public function verifiableForIntegration(Integration $integration): VerifiableDriverInterface
    {
        return $this->verifiableFor($integration->provider);
    }

    public function has(Provider $provider): bool
    {
        foreach ($this->drivers as $driver) {
            if (true === $driver->supports($provider)) {
                return true;
            }
        }

        return false;
    }
}
