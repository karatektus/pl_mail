<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Domain\Enum\Theme\Theme;
use App\Entity\Embeddable\Appearance;
use App\Entity\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Mirrors the user's theme and language into a cookie, for the pages that
 * cannot ask.
 *
 * An error page is rendered with no user at all, and not because it forgot to
 * look. A 404 is thrown by the router at priority 32 of kernel.request — before
 * the firewall at 8 and before UserLocaleSubscriber at 6 — so neither has run
 * by the time the exception is handled, and the token storage is empty for the
 * rest of the cycle. The result is what was reported: a German user in a
 * "Beere" theme gets an English 404 in beige "Papier", which reads as a
 * different application.
 *
 * The error template cannot solve this for itself, and its docblock explains
 * why in detail: it must not need a session, a user, a database or anything
 * else that might be the thing that broke. A template that throws while
 * handling an exception leaves the visitor on Symfony's bare fallback, which is
 * the one case where the branded page mattered.
 *
 * A cookie is the one channel that survives all of that. It is written on
 * ordinary responses, where the user IS known, and read by anything rendering
 * without one. Nothing secret goes in it — a theme name and a locale, both of
 * which the page it comes from displays anyway.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class AppearanceCookieSubscriber
{
    /** Read by AppearanceExtension and by the error template. */
    public const string COOKIE = 'plmail_ui';

    /**
     * A year. This is a display hint with no security meaning, and the point of
     * it is to still be there on the day something breaks — which is not
     * usually the day after it was set.
     */
    private const int LIFETIME = 31_536_000;

    public function __construct(private Security $security)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return;
        }

        $wanted = $this->value($user->appearance->theme->value, $event->getRequest()->getLocale());

        // Only when it changed. Sending an identical Set-Cookie on every
        // response is noise on the wire and, more to the point, makes every
        // response uncacheable for no gain.
        if ($wanted === $event->getRequest()->cookies->get(self::COOKIE)) {
            return;
        }

        $event->getResponse()->headers->setCookie(
            Cookie::create(self::COOKIE, $wanted)
                ->withExpires(time() + self::LIFETIME)
                ->withPath('/')
                // Readable by JavaScript is not needed and not offered; the
                // theme bootstrap in the layout uses localStorage for its own
                // reasons and is unaffected by this.
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );
    }

    /** `theme|locale`, which is all either reader needs. */
    private function value(string $theme, string $locale): string
    {
        return $theme . '|' . $locale;
    }

    /**
     * The theme and locale a request carries, or nulls.
     *
     * Static because both readers are places with no service container worth
     * the name — a Twig extension that must not fail, and a template.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function read(?string $cookie): array
    {
        if (null === $cookie || '' === $cookie) {
            return [null, null];
        }

        $parts = explode('|', $cookie, 2);

        $theme  = '' !== $parts[0] ? $parts[0] : null;
        $locale = isset($parts[1]) && '' !== $parts[1] ? $parts[1] : null;

        // Both are compared against known values by their readers, so a
        // hand-edited cookie can only ever fail to match.
        return [$theme, $locale];
    }

    /** @return Appearance the stored theme applied to the defaults */
    public static function appearanceFrom(?string $cookie): Appearance
    {
        $appearance = new Appearance();

        [$theme] = self::read($cookie);

        if (null === $theme) {
            return $appearance;
        }

        $known = Theme::tryFrom($theme);

        if (null !== $known) {
            $appearance->theme = $known;
        }

        return $appearance;
    }
}
