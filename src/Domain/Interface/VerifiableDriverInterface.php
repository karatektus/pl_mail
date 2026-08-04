<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;

/**
 * A driver that can be asked whether its credentials still work.
 *
 * Split out of IntegrationDriverInterface, which is file-coupled in every other
 * method — list, download, upload, shareLink, thumbnail all take or return
 * files. `verify()` is the one thing every connection has in common whatever it
 * connects to, and it is called for *any* integration by the connect, test and
 * onboarding paths.
 *
 * The alternative was giving a calendar driver five throwing file stubs to
 * satisfy an interface it has no business implementing, which is the same
 * mistake Capability::Search would have been before SearchableDriverInterface
 * existed. That is the precedent this follows.
 */
interface VerifiableDriverInterface
{
    public function supports(Provider $provider): bool;

    /**
     * Probe the credentials. Returns normally on success.
     *
     * @throws IntegrationException if the service is unreachable or rejects us
     */
    public function verify(Integration $integration): void;
}
