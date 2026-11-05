<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Integration\BrowserLoginStart;
use App\Domain\DTO\Integration\ObtainedCredential;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;

/**
 * A way for a service to hand plMail a credential after the person approves
 * in their own browser, instead of creating one by hand and pasting it (#235).
 *
 * Nextcloud's Login Flow v2 is the first. The interface is the shape that flow
 * and the obvious next ones share — an OpenCloud or ownCloud flow through
 * OIDC, a device-code grant — so a second one is a class implementing this and
 * nothing else: BrowserLoginService keeps the in-flight state, the controller
 * and the button never name a provider, and the result is saved through the
 * same probe as the manual form.
 *
 * Two calls rather than one blocking one, because the person may take minutes
 * and a web request cannot wait that long:
 *
 *   start() asks the server for a login address and whatever the flow needs
 *   to collect the result later — Nextcloud's poll token and endpoint. That
 *   state is the flow's own business; BrowserLoginService stores it encrypted
 *   and hands it back unread.
 *
 *   poll() is asked every couple of seconds until it returns a credential,
 *   returns null for "not yet", or throws because the flow can no longer
 *   succeed (expired, refused, the server went away).
 *
 * What comes back is a username and a secret — the same pair the manual form
 * stores, so nothing downstream knows how a connection was made.
 */
interface BrowserLoginFlowInterface
{
    public function supports(Provider $provider): bool;

    /**
     * Ask the server at $baseUrl to begin a login.
     *
     * The address is the one plMail will store: the admin's pin, or the
     * user's after IntegrationUrlValidator has allowed it. A flow must refuse a
     * login or poll address on a different origin, so a server cannot steer
     * the person or the poll somewhere else.
     *
     * @throws IntegrationException with a message fit to show the person
     */
    public function start(string $baseUrl): BrowserLoginStart;

    /**
     * Whether the person has approved yet.
     *
     * @param array<string,string> $state what start() returned, unchanged
     *
     * @return ObtainedCredential|null null while still waiting
     *
     * @throws IntegrationException when the flow can no longer succeed
     */
    public function poll(string $baseUrl, array $state): ?ObtainedCredential;
}
