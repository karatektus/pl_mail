<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Enum\Mail\ComposeAction;
use App\Entity\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `compose_action_order()` — the CSS `order` of each button in the compose
 * window's action bar, by ComposeAction value, for the person signed in.
 *
 * A function rather than a controller variable for the reason
 * AiAvailabilityExtension gives: compose/_window.html.twig is rendered on four
 * paths, two of them `only`, and strict_variables is on.
 *
 * An `order` rather than markup in a different sequence: the buttons are long
 * blocks of markup with their own controllers, and drawing them through a loop
 * over the order would turn the template inside out for a number per button.
 * Send keeps the flex default of 0 and ⋮ takes one past the last, so both stay
 * where they are.
 */
final class ComposeActionOrderExtension extends AbstractExtension
{
    public function __construct(private readonly Security $security)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('compose_action_order', $this->order(...))];
    }

    /** @return array<string, int> 1 for the first button, ⋮ under the key `more` */
    public function order(): array
    {
        $user = $this->security->getUser();
        $order = $user instanceof User ? $user->composeActionOrder : ComposeAction::cases();
        $positions = [];

        foreach ($order as $index => $action) {
            $positions[$action->value] = $index + 1;
        }

        $positions['more'] = count($positions) + 1;

        return $positions;
    }
}
