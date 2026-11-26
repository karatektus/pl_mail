<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Mail\ForwardAgent;
use App\Entity\User\User;
use App\Service\Mail\ForwardAgents;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `forward_agents(user)` — the person's agents for "Forward to agent", for the
 * card on Settings → Compose.
 *
 * A function rather than a variable SettingsController passes, so the card can
 * sit on the Compose page without that controller knowing it is there.
 */
final class ForwardAgentsExtension extends AbstractExtension
{
    public function __construct(
        private readonly ForwardAgents $agents,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('forward_agents', $this->agents(...)),
        ];
    }

    /** @return list<ForwardAgent> */
    public function agents(User $user): array
    {
        return $this->agents->all($user);
    }
}
