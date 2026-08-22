<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Event;

use App\Domain\Enum\Theme\Theme;
use App\Infrastructure\Event\Subscriber\AppearanceCookieSubscriber;
use App\Infrastructure\Event\Subscriber\UserLocaleSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Translation\Translator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An error page is in the reader's language and the reader's theme.
 *
 * Reported from a crawl: a German user in a "Beere" theme mistypes a URL and
 * gets an English 404 in beige "Papier" — which does not read as the same
 * application, and arrives at the moment somebody is already confused.
 *
 * It was not an oversight in the template. A 404 is thrown by the ROUTER, at
 * priority 32 of kernel.request — before the firewall at 8 and before
 * UserLocaleSubscriber at 6. Nothing has authenticated by the time the
 * exception is handled: the token storage is empty, the request still carries
 * the default locale, and the appearance falls through to its defaults. Every
 * other page in the application is fine, which is exactly why this survived.
 *
 * The fix cannot be "look the user up", and the error template's own docblock
 * says why at length: it must not need a session, a user or a database, because
 * any of those may be the thing that broke. So the two display hints travel in
 * a cookie written on ordinary responses, where the user IS known.
 *
 * Tested at this level rather than through the browser because the test
 * environment runs with APP_DEBUG on, where an exception renders Symfony's
 * diagnostic page and the application's template is never reached — a test
 * against that would assert nothing a user could ever see.
 */
final class ErrorPageAppearanceTest extends TestCase
{
    public function testTheLocaleComesFromTheCookieWhenNobodyAuthenticated(): void
    {
        $translator = new Translator('en');
        $subscriber = new UserLocaleSubscriber(new TokenStorage(), $translator);

        $request = Request::create('/gibtesnicht');
        $request->cookies->set(AppearanceCookieSubscriber::COOKIE, 'nord|de');

        $subscriber->onKernelException($this->exception($request));

        self::assertSame('de', $request->getLocale());
        self::assertSame('de', $translator->getLocale(), 'the page renders through the translator, not the request');
    }

    /**
     * The request wins when it has been through the firewall.
     *
     * Every exception that is NOT a routing failure — a 500 from inside a
     * controller — has already had the user's locale applied, and re-deriving
     * it from a stale cookie would be the one way this could make things worse.
     */
    public function testAnAuthenticatedRequestIsLeftAlone(): void
    {
        $translator = new Translator('en');
        $storage    = new TokenStorage();
        $storage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
            new \Symfony\Component\Security\Core\User\InMemoryUser('someone', null),
            'main',
        ));

        $subscriber = new UserLocaleSubscriber($storage, $translator);

        $request = Request::create('/boom');
        $request->setLocale('en');
        $request->cookies->set(AppearanceCookieSubscriber::COOKIE, 'nord|de');

        $subscriber->onKernelException($this->exception($request));

        self::assertSame('en', $request->getLocale());
    }

    public function testAnUnknownLocaleIsIgnoredRatherThanApplied(): void
    {
        $translator = new Translator('en');
        $subscriber = new UserLocaleSubscriber(new TokenStorage(), $translator);

        $request = Request::create('/gibtesnicht');
        $request->cookies->set(AppearanceCookieSubscriber::COOKIE, 'nord|xx_XX');

        $subscriber->onKernelException($this->exception($request));

        self::assertSame('en', $request->getLocale());
    }

    public function testTheThemeComesFromTheSameCookie(): void
    {
        self::assertSame(Theme::Nord, AppearanceCookieSubscriber::appearanceFrom('nord|de')->theme);
    }

    /**
     * A cookie is a thing a reader can edit, so both halves are matched against
     * known values and anything else falls back rather than being rendered.
     */
    public function testAHandEditedCookieFallsBackToTheDefaults(): void
    {
        $defaults = AppearanceCookieSubscriber::appearanceFrom(null)->theme;

        self::assertSame($defaults, AppearanceCookieSubscriber::appearanceFrom('../../etc|xx')->theme);
        self::assertSame($defaults, AppearanceCookieSubscriber::appearanceFrom('')->theme);
        self::assertSame($defaults, AppearanceCookieSubscriber::appearanceFrom('nonsense')->theme);
    }

    private function exception(Request $request): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new NotFoundHttpException('No route found'),
        );
    }
}
