<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Account;

use App\Domain\Enum\Account\AccountService;
use App\Domain\Enum\Account\MailProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A grant is only as wide as what the account is used for (#575).
 *
 * The person this is for connected Gmail for its calendar. Asking them for
 * `https://mail.google.com/` — full access to every message — would be asking
 * for something they said they did not want, and a health check that then
 * called the missing mail scope a fault would put a card on their screen that
 * only granting it could clear. Both halves are pinned here: what is asked
 * for, and what counts as missing.
 */
final class MailProviderServiceScopesTest extends TestCase
{
    /** @return iterable<string, array{MailProvider}> */
    public static function providers(): iterable
    {
        yield 'Google'    => [MailProvider::Google];
        yield 'Microsoft' => [MailProvider::Microsoft];
    }

    #[DataProvider('providers')]
    public function testACalendarOnlyGrantAsksForNoMailScope(MailProvider $provider): void
    {
        $asked = $provider->scopesFor([AccountService::Calendar]);

        foreach ($provider->serviceScopes(AccountService::Mail) as $mailScope) {
            self::assertNotContains($mailScope, $asked, 'a calendar-only connect must not ask for the mailbox');
        }

        foreach ($provider->calendarScopes() as $calendarScope) {
            self::assertContains($calendarScope, $asked);
        }

        self::assertContains('openid', $asked, 'the sign-in scopes are asked for whatever the account is for');
    }

    #[DataProvider('providers')]
    public function testEveryServiceTogetherIsWhatAConnectAlwaysAskedFor(MailProvider $provider): void
    {
        self::assertEqualsCanonicalizing($provider->scopes(), $provider->scopesFor(AccountService::cases()));

        foreach (AccountService::cases() as $service) {
            foreach ($provider->serviceScopes($service) as $scope) {
                self::assertContains($scope, $provider->scopes(), sprintf('%s is still part of the full grant', $scope));
            }
        }
    }

    #[DataProvider('providers')]
    public function testACalendarOnlyGrantIsCompleteForACalendarOnlyAccount(MailProvider $provider): void
    {
        $granted = implode(' ', $provider->scopesFor([AccountService::Calendar]));

        self::assertSame([], $provider->missingScopes($granted, [AccountService::Calendar]));
    }

    #[DataProvider('providers')]
    public function testTheSameGrantIsShortForAnAccountThatWantsMail(MailProvider $provider): void
    {
        $granted = implode(' ', $provider->scopesFor([AccountService::Calendar]));
        $missing = $provider->missingScopes($granted, [AccountService::Mail, AccountService::Calendar]);

        self::assertNotEmpty($missing);
        self::assertSame([], array_diff($missing, $provider->serviceScopes(AccountService::Mail)), 'only the mail scopes are missing');
    }

    public function testAnAbsentServiceListMeansEveryService(): void
    {
        self::assertSame(AccountService::cases(), AccountService::fromList(null));
        self::assertSame(AccountService::cases(), AccountService::fromList([]));
        self::assertSame(AccountService::cases(), AccountService::fromList(['nonsense']));
        self::assertSame([AccountService::Calendar], AccountService::fromList(['calendar', 'calendar']));
        self::assertSame([AccountService::Mail, AccountService::Contacts], AccountService::fromList('mail,contacts'));
    }
}
