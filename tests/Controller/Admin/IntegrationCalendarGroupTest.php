<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\IntegrationProviderController;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Integration\ServiceKind;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Which services Admin → Integrations lists under Calendars (#212).
 *
 * The group is Provider::offersCalendars() less what the file group already
 * lists, so that Nextcloud, which offers calendars and is a file service, has
 * one row and one form. No database: the answer is the enum's.
 */
final class IntegrationCalendarGroupTest extends TestCase
{
    public function testTheGroupIsCalDavAndTheFeedAndNotNextcloud(): void
    {
        $group = $this->calendarProviders();

        self::assertSame([Provider::CalDav, Provider::Ics], $group);
        self::assertNotContains(Provider::Nextcloud, $group);
        self::assertTrue(Provider::Nextcloud->offersCalendars(), 'the case this group has to leave out on purpose');
    }

    /**
     * Every provider that offers calendars has a row somewhere, and only one.
     *
     * The guard for a provider added later: it says offersCalendars() and
     * appears in whichever group fits, rather than in neither or in both.
     */
    public function testEveryCalendarProviderIsListedExactlyOnce(): void
    {
        $files    = Provider::of(ServiceKind::Files);
        $calendar = $this->calendarProviders();

        foreach (Provider::cases() as $provider) {
            $rows = (int) in_array($provider, $files, true) + (int) in_array($provider, $calendar, true);

            if (true === $provider->offersCalendars()) {
                self::assertSame(1, $rows, $provider->value . ' offers calendars and needs exactly one row');
            }

            if (true === in_array($provider, $calendar, true)) {
                self::assertTrue($provider->offersCalendars(), $provider->value . ' is in the calendar group without offering calendars');
            }
        }
    }

    /**
     * @return list<Provider>
     */
    private function calendarProviders(): array
    {
        /** @var list<Provider> */
        return new ReflectionMethod(IntegrationProviderController::class, 'calendarProviders')->invoke(null);
    }
}
