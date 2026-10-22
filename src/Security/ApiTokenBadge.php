<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User\ApiToken;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/**
 * Carries the app password a request authenticated with, so a later layer can
 * ask what it may do.
 *
 * ── Why a badge ──────────────────────────────────────────────────────────────
 * The firewall proves WHO the caller is, and that is all it is built to say.
 * But "who" is not the whole question for a JMAP request: the same person
 * holding a read-only app password and a browser tab must be treated
 * differently by JmapProcessor, and the processor runs after the firewall with
 * only a User in hand.
 *
 * A badge is the mechanism Symfony provides for exactly this — a fact
 * established during authentication that the passport carries forward, and
 * which the Security token then holds so a controller can read it.
 *
 * ── Always resolved ──────────────────────────────────────────────────────────
 * There is nothing to defer: the authenticator has already loaded the entity by
 * the time it builds the passport. isResolved() answers true unconditionally,
 * because Symfony refuses the whole authentication if any badge says otherwise,
 * and a badge that reported "not resolved" would lock out the very clients this
 * exists to scope.
 *
 * ── Why the token is nullable ────────────────────────────────────────────────
 * The firewall has two credential types, and a JWT — when something issues one
 * — is not an app password. Null means "not an app password", which is also
 * what the browser session is, and both mean the caller is unscoped.
 */
final class ApiTokenBadge implements BadgeInterface
{
    public function __construct(
        public readonly ?ApiToken $token = null,
    ) {
    }

    public function isResolved(): bool
    {
        return true;
    }
}
