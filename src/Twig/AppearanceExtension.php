<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Embeddable\Appearance;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Infrastructure\Event\Subscriber\AppearanceCookieSubscriber;
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
        // For the no-user case above: the cookie is the only place the theme
        // survives a request the firewall never ran for.
        private readonly RequestStack       $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('appearance', $this->appearance(...)),
            new TwigFunction('appearance_class', $this->appearanceClass(...)),
            new TwigFunction('appearance_theme', $this->appearanceTheme(...)),
            new TwigFunction('appearance_motion', $this->appearanceMotion(...)),
            new TwigFunction('appearance_vars', $this->appearanceVars(...)),
            new TwigFunction('background_preset_css', $this->backgrounds->preset(...)),
            new TwigFunction('background_solid_css', $this->backgrounds->solid(...)),
        ];
    }

    public function appearance(): Appearance
    {
        $user = $this->security->getUser();

        if ($user instanceof User) {
            return $user->appearance;
        }

        // No user is not always "not signed in". An error page is rendered with
        // an empty token storage even for somebody who is: a 404 is thrown by
        // the router at priority 32 of kernel.request, before the firewall at
        // 8, so nothing has authenticated by the time the exception is handled.
        // Falling straight through to the defaults is what put a German user in
        // a beige "Papier" 404 while every other page was their own theme.
        //
        // The cookie is written on ordinary responses, where the user IS known
        // — see AppearanceCookieSubscriber, which also explains why the error
        // template cannot simply look the user up for itself.
        return AppearanceCookieSubscriber::appearanceFrom(
            $this->requests->getCurrentRequest()?->cookies->get(AppearanceCookieSubscriber::COOKIE),
        );
    }

    public function appearanceClass(): string
    {
        return $this->renderer->htmlClass($this->appearance());
    }

    public function appearanceTheme(): string
    {
        return $this->appearance()->theme->value;
    }

    /**
     * The motion tier, for `data-motion` on <html>.
     *
     * Server-rendered like the theme and for the same reason: a class the
     * browser only learns about once JavaScript has run is a first paint with
     * the wrong one, and for motion that means every element on the page
     * playing its entrance the instant the setting arrives.
     */
    public function appearanceMotion(): string
    {
        return $this->appearance()->motion->value;
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
