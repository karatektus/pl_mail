<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Account;

use App\Domain\Enum\Account\MailProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading what a provider actually granted, in the provider's own spelling.
 *
 * The question this answers is why three calendars can sit there saying
 * "stopped syncing" on an account whose mail works perfectly. Google's consent
 * screen ticks sensitive scopes individually, so a user can grant mail, decline
 * calendar, and come back with a token that never fails — and the shortfall is
 * discovered days later as a 403.
 *
 * Two ways to get this wrong, and both are worse than not doing it at all:
 *
 *  • Comparing literally. Microsoft is asked for
 *    `https://graph.microsoft.com/Calendars.ReadWrite` and answers with a bare
 *    `Calendars.ReadWrite`. A literal comparison reports EVERY Microsoft
 *    account as missing calendar access.
 *  • Trimming to the last path segment. Google's `auth/calendar` and
 *    `auth/calendar.events` are different permissions, and collapsing them
 *    would accept the wrong one as proof of the right one.
 */
final class MailProviderScopesTest extends TestCase
{
    /** @return iterable<string, array{MailProvider, string|null, bool|null}> */
    public static function grants(): iterable
    {
        yield 'Google, calendar granted' => [
            MailProvider::Google,
            'https://mail.google.com/ https://www.googleapis.com/auth/calendar',
            true,
        ];

        yield 'Google, calendar declined on the consent screen' => [
            MailProvider::Google,
            'https://mail.google.com/ https://www.googleapis.com/auth/userinfo.email',
            false,
        ];

        // The trap that makes trimming to the last segment wrong: a real,
        // narrower calendar permission that is NOT the one asked for.
        yield 'Google, a neighbouring calendar scope is not the one asked for' => [
            MailProvider::Google,
            'https://mail.google.com/ https://www.googleapis.com/auth/calendar.events',
            false,
        ];

        // The trap that makes literal comparison wrong.
        yield 'Microsoft answers with the bare scope name' => [
            MailProvider::Microsoft,
            'openid profile offline_access Mail.ReadWrite Calendars.ReadWrite',
            true,
        ];

        yield 'Microsoft, in whatever case it feels like' => [
            MailProvider::Microsoft,
            'Mail.ReadWrite calendars.readwrite',
            true,
        ];

        yield 'Microsoft, a tenant that withholds calendars' => [
            MailProvider::Microsoft,
            'openid profile offline_access Mail.ReadWrite',
            false,
        ];

        // OAuth 2.0 requires `scope` in the response only when the grant
        // DIFFERS from the request, so its absence means "you got what you
        // asked for" — and an account connected before this was recorded looks
        // identical. Neither is evidence of anything missing.
        yield 'nothing came back' => [MailProvider::Google, null, null];
        yield 'an empty string came back' => [MailProvider::Microsoft, '   ', null];
    }

    #[DataProvider('grants')]
    public function testItReadsWhatTheProviderGranted(
        MailProvider $provider,
        ?string $granted,
        ?bool $expected,
    ): void {
        self::assertSame($expected, $provider->grantsCalendarAccess($granted));
    }

    /**
     * The scope it looks for is the scope it asks for.
     *
     * Written as a relationship rather than as a literal string: the two are
     * the same fact, and a test repeating the URL would go on passing after
     * somebody changed one of them.
     */
    public function testTheGrantedSetIsJudgedAgainstTheRequestedOne(): void
    {
        foreach (MailProvider::cases() as $provider) {
            self::assertNotSame([], $provider->calendarScopes(), $provider->value);

            self::assertTrue(
                $provider->grantsCalendarAccess(implode(' ', $provider->scopes())),
                sprintf('%s: the full requested set must count as granting calendar access', $provider->value),
            );

            $withoutCalendar = array_diff($provider->scopes(), $provider->calendarScopes());

            self::assertFalse(
                $provider->grantsCalendarAccess(implode(' ', $withoutCalendar)),
                sprintf('%s: the requested set minus calendar must not', $provider->value),
            );
        }
    }
}
