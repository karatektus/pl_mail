<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User\User;
use App\Service\Onboarding\OnboardingFlow;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Whether the layout should open the setup wizard for whoever is looking.
 *
 * A global rather than a check inside the layout: the layout renders on every
 * authenticated page, and "is this user still pending" belongs to
 * OnboardingFlow, not to a template.
 *
 * Cheap by construction — pending-ness is one key in the user's settings bag,
 * already loaded with the user, so this costs no query.
 */
final readonly class OnboardingGlobal
{
    public function __construct(
        private OnboardingFlow $flow,
        private Security $security,
    ) {
    }

    public function isPending(): bool
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return false;
        }

        return $this->flow->isPending($user);
    }
}
