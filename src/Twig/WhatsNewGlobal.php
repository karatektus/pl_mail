<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User\User;
use App\Service\Onboarding\OnboardingFlow;
use App\Service\System\WhatsNew\WhatsNew;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * What the layout and the top bar ask about What's new (#165).
 *
 * A global for the reason onboarding's is: the top bar and the layout render
 * on every signed-in page, and there is no one controller to thread it
 * through. Cheap: the settings bag comes with the user, and the changelog is a
 * cache read.
 */
final readonly class WhatsNewGlobal
{
    public function __construct(
        private WhatsNew       $whatsNew,
        private OnboardingFlow $onboarding,
        private Security       $security,
    ) {
    }

    /** The dot on the ? and on What's new in its menu. */
    public function hasDot(): bool
    {
        $user = $this->user();

        return null !== $user && $this->whatsNew->shouldAnnounce($user);
    }

    /**
     * Whether the layout opens the dialog by itself. Not while setup is still
     * pending: somebody who has not added an account yet has a wizard on
     * screen, and release notes on top of it would be two dialogs fighting
     * for one person who has not got as far as either.
     */
    public function opensByItself(): bool
    {
        $user = $this->user();

        return null !== $user
            && false === $this->onboarding->isPending($user)
            && $this->whatsNew->shouldAnnounce($user);
    }

    /** The running release, `0.3.6`, for the menu and the browser-side guard. */
    public function running(): ?string
    {
        return $this->whatsNew->running();
    }

    private function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
