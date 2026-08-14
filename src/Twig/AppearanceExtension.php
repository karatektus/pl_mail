<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Embeddable\Appearance;
use App\Entity\User\User;
use App\Service\Appearance\AppearanceRenderer;
use App\Service\Appearance\BackgroundResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AppearanceExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security           $security,
        private readonly AppearanceRenderer $renderer,
        /*
         * Exposed so the settings panel can render each background tile's
         * `--app-bg` as the string the renderer would write for it. The rule
         * this serves is in settings--appearance: a live switch must land
         * exactly where a reload would, and the only way to guarantee that is
         * for both to come out of the same method.
         */
        private readonly BackgroundResolver $backgrounds,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('appearance', $this->appearance(...)),
            new TwigFunction('appearance_class', $this->appearanceClass(...)),
            new TwigFunction('appearance_theme', $this->appearanceTheme(...)),
            new TwigFunction('appearance_vars', $this->appearanceVars(...)),
            new TwigFunction('background_preset_css', $this->backgrounds->preset(...)),
            new TwigFunction('background_solid_css', $this->backgrounds->solid(...)),
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
            true === $user instanceof User ? $user->id : null,
        );
    }
}
