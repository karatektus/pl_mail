<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Enum\Integration\Capability;
use App\Entity\Integration\Integration;
use App\Repository\Integration\IntegrationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The current user's integrations, by capability, for templates that cannot be
 * handed them.
 *
 * The attachment chip is rendered from five call sites across two partials,
 * several of which include with `only`. Threading a variable through all of
 * them to reach one dropdown would touch every render path that shows a
 * message, and quietly break whichever one was missed. A global keeps the
 * dependency where it is used.
 *
 * Per-request memoised, like SidebarCounts: a thread renders one chip per
 * attachment, and each would otherwise be a query.
 */
class IntegrationsGlobal implements ResetInterface
{
    /** @var array<string,list<Integration>> */
    private array $byCapability = [];

    /**
     * Worker-mode hygiene - see LogAlertGlobal::reset(), the sibling whose
     * staleness was actually caught in the wild.
     */
    public function reset(): void
    {
        $this->byCapability = [];
    }

    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly Security              $security,
    ) {
    }

    /**
     * Connections that can receive a file — what the "Save to…" menu offers.
     *
     * @return list<Integration>
     */
    public function forUpload(): array
    {
        return $this->supporting(Capability::Upload);
    }

    /**
     * Connections a file can be pulled out of.
     *
     * @return list<Integration>
     */
    public function forDownload(): array
    {
        return $this->supporting(Capability::Download);
    }

    /**
     * @return list<Integration>
     */
    private function supporting(Capability $capability): array
    {
        if (true === isset($this->byCapability[$capability->value])) {
            return $this->byCapability[$capability->value];
        }

        $user = $this->security->getUser();

        return $this->byCapability[$capability->value] = null === $user
            ? []
            : $this->integrations->findSupportingForUser($user, $capability);
    }
}
