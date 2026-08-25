<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Demo\DemoMode;
use App\Service\Demo\DemoScenarios;
use App\Entity\User\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * What the templates need to know about a demo: whether this is one, and what
 * the button will deliver next.
 *
 * A global rather than a controller variable, for the reason the neighbouring
 * ones give: the demo bar renders from the layout on every authenticated page,
 * so there is no single controller in the middle to thread it through.
 *
 * Naming the next delivery is what stops the button being a mystery box. A
 * visitor who has pressed it twice should be able to see that a third press
 * brings something else, and the honest way to show that is to say what.
 */
final readonly class DemoGlobal
{
    public function __construct(
        private DemoMode      $demoMode,
        private DemoScenarios $scenarios,
        private Security      $security,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->demoMode->isEnabled();
    }

    /**
     * The subject of the message the receive button will deliver next, or null
     * when there is nobody to deliver to.
     */
    public function nextSubject(): ?string
    {
        if (false === $this->demoMode->isEnabled()) {
            return null;
        }

        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return null;
        }

        [$scenario] = $this->scenarios->next($user);

        return $scenario->subject;
    }
}
