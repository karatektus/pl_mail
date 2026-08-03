<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User\User;
use App\Service\Onboarding\OnboardingFlow;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

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
        private RequestStack $requestStack,
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

    /**
     * Identifies this login, for the browser-side "already offered" guard.
     *
     * That guard lives in sessionStorage so a Turbo restoration visit does not
     * re-open the wizard — but sessionStorage belongs to the tab, and outlives
     * both the session and the user. A tab that had once dismissed the wizard
     * would never see it again: not after signing out and back in, not as a
     * different user, and not after `app:reset --full` had wiped the install
     * and built a new administrator in the same tab.
     *
     * Keying the guard on the session id and the user id makes it mean "already
     * offered to this person, this session", which is what it was always meant
     * to mean. Symfony migrates the session id on login, so signing in produces
     * a new key by itself.
     */
    public function offerKey(): string
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return '';
        }

        $session = $this->requestStack->getSession();

        return substr(hash('sha256', $session->getId().'|'.$user->id), 0, 16);
    }
}
