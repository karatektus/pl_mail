<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Entity\Integration\Integration;

/**
 * Finds the driver for a provider.
 *
 * Same shape as MailSenderRegistry: drivers are injected as a tagged iterator
 * and the first that claims the provider wins. A provider with no driver is a
 * stub, and asking for one is a programming error rather than a user error —
 * every UI path checks Provider::isImplemented() before it gets here.
 */
final readonly class IntegrationDriverRegistry
{
    /**
     * @param iterable<IntegrationDriverInterface> $drivers
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
