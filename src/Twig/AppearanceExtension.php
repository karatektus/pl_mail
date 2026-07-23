<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Embeddable\Appearance;
use App\Entity\User;
use App\Service\Appearance\AppearanceRenderer;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AppearanceExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security           $security,
        private readonly AppearanceRenderer $renderer,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('appearance', $this->appearance(...)),
            new TwigFunction('appearance_class', $this->appearanceClass(...)),
            new TwigFunction('appearance_theme', $this->appearanceTheme(...)),
            new TwigFunction('appearance_vars', $this->appearanceVars(...)),
        ];
    }

    public function appearance(): Appearance
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return new Appearance();
        }

        return $user->appearance;
    }

    public function appearanceClass(): string
    {
        return $this->renderer->htmlClass($this->appearance());
    }

    public function appearanceTheme(): string
    {
        return $this->appearance()->theme->value;
    }

    public function appearanceVars(): string
    {
        $user = $this->security->getUser();

        return $this->renderer->cssVariables(
            $this->appearance(),
            true === $user instanceof User ? $user->getId() : null,
        );
    }
}
