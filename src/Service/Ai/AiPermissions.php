<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Ai\AiFeature;
use App\Entity\User\User;

/**
 * The one place the two answers are ANDed together.
 *
 * There are two switches for every AI feature and they are not peers. The
 * installation's — AiSettings, set by an administrator — is a CEILING: it names
 * a host, names a model, and says which of the four features this box is
 * allowed to be used for at all. The person's is a floor underneath it,
 * subtracting from what they have been offered.
 *
 * Written once, here, because the failure mode of writing it eleven times is
 * that the eleventh copy gets the order wrong and a user preference lets
 * something past a switch an administrator turned off. Every caller that has a
 * user asks this; nothing asks AiPreferences::allows() directly.
 *
 * WHY AiSettings DID NOT SIMPLY LEARN ABOUT USERS
 * ───────────────────────────────────────────────
 * It is a global singleton entity with no user relation and no repository
 * access. Giving enabledFor() a User parameter would push it onto every
 * existing call site — including the four inside AiAssistant, which runs in
 * workers and holds no Security — or put a service locator inside an entity.
 * The AND belongs one layer above the entity, exactly as
 * InsightExtractorRegistry::isEnabledFor(User, string) sits one layer above the
 * extractor it is deciding about.
 *
 * AiAssistant is deliberately NOT changed. It stays the one door to the model
 * and it keeps enforcing the ceiling at its own four gates, so a caller that
 * forgets this class still cannot reach a model for a feature the installation
 * has off. What it cannot do is enforce the floor, because it has no way to
 * know whose mail it is holding — which is why the four things in src/ that
 * build content for a model take a User in their signature instead.
 */
final readonly class AiPermissions
{
    public function __construct(private AiAssistant $ai)
    {
    }

    public function allows(?User $user, AiFeature $feature): bool
    {
        // The installation's answer FIRST and always. There is no user value
        // that can get past a false here, which is what makes the
        // administrator's switch mean what it says.
        if (false === $this->ai->isEnabledFor($feature)) {
            return false;
        }

        // No user, no consent. Not a convenience default — the callers that can
        // arrive here without one are workers running unattended over
        // somebody's mail, and "we could not work out whose mail this is" is
        // never a reason to send it to a model. An EmbedMessagesMessage
        // serialised by a build from before anybody could say no is exactly
        // this case, and it is dropped rather than embedded.
        if (null === $user) {
            return false;
        }

        return $user->aiPreferences->allows($feature);
    }

    /**
     * Whether an administrator has switched ANY of this on.
     *
     * The settings section's visibility, and deliberately not a per-user
     * question: a user who has switched all four of their own off must still
     * be able to find the page that switches them back on.
     */
    public function anyAdminEnabled(): bool
    {
        // The settings row read ONCE and then asked four times, rather than
        // four calls to isEnabledFor(). AiSettingsRepository::current() goes
        // through findOneBy(), which issues SQL every time — only find()-by-id
        // is answered from Doctrine's identity map — so the obvious loop would
        // put four queries on every settings page render, on every tab,
        // because the navigation asks this to decide whether the entry exists.
        $settings = $this->ai->settings();

        foreach (AiFeature::cases() as $feature) {
            if (true === $settings->enabledFor($feature)) {
                return true;
            }
        }

        return false;
    }
}
