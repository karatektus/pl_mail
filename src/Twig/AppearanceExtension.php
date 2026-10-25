<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Helper\AppIconStorage;
use App\Domain\Helper\CustomLogoStorage;
use App\Entity\Embeddable\Appearance;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Infrastructure\Event\Subscriber\AppearanceCookieSubscriber;
use App\Entity\User\User;
use App\Service\Appearance\AppearanceRenderer;
use App\Service\Appearance\BackgroundResolver;
use App\Service\Appearance\LogoIcons;
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
        // The icon URLs, spelled in one place with the drawing's version in
        // them — see LogoIcons for why every one of them carries it.
        private readonly LogoIcons          $logoIcons,
        // The installed app's PNGs, which the user's own browser draws (#141).
        private readonly AppIconStorage     $appIcons,
        // A logo of the user's own (#48).
        private readonly CustomLogoStorage  $customLogos,
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
            new TwigFunction('logo_icon_url', $this->logoIcons->url(...)),
            new TwigFunction('logo_favicon_url', $this->logoIcons->faviconUrl(...)),
            new TwigFunction('logo_url', $this->logoIcons->logoUrl(...)),
            new TwigFunction('logo_bleed_url', $this->logoIcons->bleedUrl(...)),
            new TwigFunction('logo_manifest_url', $this->logoIcons->manifestUrl(...)),
            new TwigFunction('logo_app_icon_url', $this->logoIcons->appIconUrl(...)),
            new TwigFunction('logo_custom_url', $this->logoIcons->customUrl(...)),
            new TwigFunction('logo_custom_manifest_url', $this->logoIcons->customManifestUrl(...)),
            new TwigFunction('logo_custom_app_icon_url', $this->logoIcons->customAppIconUrl(...)),
            new TwigFunction('logo_custom', $this->logoCustom(...)),
            new TwigFunction('logo_current', $this->logoCurrent(...)),
            new TwigFunction('app_icons', $this->appIcons(...)),
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

    /**
     * The signed-in user's installed-app icons: the key their URLs carry, and
     * whether the PNGs for the current choice still have to be drawn (a choice
     * made before this existed, or a deploy that changed the drawing). Null
     * for nobody: a page without a user links the static wax seal.
     *
     * @return array{key: string, missing: bool}|null
     */
    public function appIcons(): ?array
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return null;
        }

        $appearance = $user->appearance;
        $custom     = $this->logoCustom();

        return [
            'key'     => $this->appIcons->keyFor((string) $user->id),
            'missing' => false === $this->appIcons->has(
                (string) $user->id,
                null === $custom
                    ? LogoIcons::choice($appearance->effectiveLogoMotif(), $appearance->effectiveLogoPaint())
                    : LogoIcons::customChoice($custom),
                $this->logoIcons->version(),
            ),
        ];
    }

    /**
     * The signed-in user's uploaded logo (#48) — its hash — when they have
     * one, whether or not it is the one worn; null otherwise.
     *
     * Only when the file is there. A user restored from a backup, or one whose
     * storage was lost, still has the hash on their row, and a page that
     * linked it would be a page with a broken tab icon; without the file the
     * motif kept underneath is what they wear.
     */
    public function logoCustom(bool $worn = true): ?string
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return null;
        }

        $hash = true === $worn ? $user->appearance->customLogo() : $user->appearance->logoCustom;

        return null !== $hash && true === $this->customLogos->has((string) $user->id, $hash) ? $hash : null;
    }

    /**
     * Every URL the layout links the logo by, for the icon the user wears: the
     * uploaded one, or their motif in its paint. Spelled here once, so the
     * tab icon, the top bar, the manifest and the iOS icon cannot wear two
     * different logos.
     *
     * `custom` is the uploaded logo's hash while it is worn, null otherwise.
     *
     * @return array{custom: string|null, icon: string, favicon: string, bar: string, barDark: string, bleed: string, manifest: string, appleTouch: string}
     */
    public function logoCurrent(): array
    {
        $icons  = $this->appIcons();
        $key    = $icons['key'] ?? null;
        $custom = $this->logoCustom();

        if (null !== $custom && null !== $key) {
            return [
                'custom'     => $custom,
                'icon'       => $this->logoIcons->customUrl('icon', $key, $custom),
                'favicon'    => $this->logoIcons->customUrl('favicon', $key, $custom),
                'bar'        => $this->logoIcons->customUrl('logo', $key, $custom),
                'barDark'    => $this->logoIcons->customUrl('logo', $key, $custom),
                'bleed'      => $this->logoIcons->customUrl('bleed', $key, $custom),
                'manifest'   => $this->logoIcons->customManifestUrl($key, $custom),
                'appleTouch' => $this->logoIcons->customAppIconUrl('bleed-180', $key, $custom),
            ];
        }

        $appearance = $this->appearance();
        $motif      = $appearance->effectiveLogoMotif();
        $paint      = $appearance->effectiveLogoPaint();

        return [
            'custom'     => null,
            'icon'       => $this->logoIcons->url($motif, $paint),
            'favicon'    => $this->logoIcons->faviconUrl($motif, $paint),
            'bar'        => $this->logoIcons->logoUrl($motif, $paint),
            'barDark'    => $this->logoIcons->logoUrl($motif, $paint, true),
            'bleed'      => $this->logoIcons->bleedUrl($motif, $paint),
            'manifest'   => $this->logoIcons->manifestUrl($motif, $paint, $key),
            'appleTouch' => null === $key ? '/icons/apple-touch-icon.png' : $this->logoIcons->appIconUrl('bleed-180', $key, $motif, $paint),
        ];
    }
}
