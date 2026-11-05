<?php

declare(strict_types=1);

namespace App\Service\Integration\BrowserLogin;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Interface\BrowserLoginFlowInterface;

/**
 * Which providers can be connected by approving in the browser, and how.
 *
 * Asked by the templates too, to decide whether the button exists — so a
 * provider gains it by having a flow and nothing else, and a provider without
 * one never shows a button that would 404.
 */
final readonly class BrowserLoginFlowRegistry
{
    /**
     * @param iterable<BrowserLoginFlowInterface> $flows
     */
    public function __construct(
        private iterable $flows,
    ) {
    }

    public function for(Provider $provider): ?BrowserLoginFlowInterface
    {
        foreach ($this->flows as $flow) {
            if (true === $flow->supports($provider)) {
                return $flow;
            }
        }

        return null;
    }

    public function supports(Provider $provider): bool
    {
        return null !== $this->for($provider);
    }
}
