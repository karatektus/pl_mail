<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Entity\User\User;
use App\Service\User\UserTimezoneResolver;
use PHPUnit\Framework\TestCase;

/**
 * Everything a user reads a date on goes through this, so the interesting
 * cases are the ones where there is no answer to give.
 *
 * The bug this guards against was not a wrong zone — it was UTC quietly
 * standing in for one. UTC is a plausible-looking clock that is nobody's, so
 * each fallback below asserts a real zone rather than merely "something".
 */
final class UserTimezoneResolverTest extends TestCase
{
    public function testReturnsTheUsersOwnZone(): void
    {
        $user = new User();
        $user->timezone = 'America/New_York';

        self::assertSame('America/New_York', new UserTimezoneResolver('Europe/Berlin')->resolve($user)->getName());
    }

    /**
     * Null is "never chose", which is the state every existing account is in —
     * so the configured default has to reach them, not the process default.
     */
    public function testFallsBackToTheConfiguredDefaultWhenTheUserHasNotChosen(): void
    {
        $resolver = new UserTimezoneResolver('Europe/Berlin');

        self::assertNull(new User()->timezone);
        self::assertSame('Europe/Berlin', $resolver->resolve(new User())->getName());
        self::assertSame('Europe/Berlin', $resolver->resolve(null)->getName());
    }

    public function testTheConfiguredDefaultIsHonouredRatherThanHardcoded(): void
    {
        self::assertSame('Pacific/Auckland', new UserTimezoneResolver('Pacific/Auckland')->resolve(null)->getName());
    }

    /**
     * An identifier the system does not know never reaches the column: the
     * set hook drops it, so the user simply stays on the default rather than
     * every later `new DateTimeZone()` throwing somewhere unrelated.
     */
    public function testRejectsAnInvalidIdentifier(): void
    {
        $user = new User();
        $user->timezone = 'Mars/Olympus_Mons';

        self::assertNull($user->timezone);
        self::assertSame('Europe/Berlin', new UserTimezoneResolver('Europe/Berlin')->resolve($user)->getName());
    }

    /**
     * A fixed offset survives `new DateTimeZone()` and is still wrong: it is
     * correct in March and an hour out in August, which is precisely the kind
     * of error nobody reports.
     */
    public function testRejectsAFixedOffsetAndAnAbbreviation(): void
    {
        foreach (['+02:00', 'CEST', ''] as $rejected) {
            $user = new User();
            $user->timezone = $rejected;

            self::assertNull($user->timezone, sprintf('rejected %s', var_export($rejected, true)));
        }
    }

    /**
     * A misconfigured default must not silently become UTC — that is the exact
     * value this whole change exists to stop being an accidental answer.
     */
    public function testAnUnusableConfiguredDefaultFallsBackToARealZone(): void
    {
        foreach (['', 'Mars/Olympus_Mons', 'not a zone'] as $configured) {
            self::assertSame(
                UserTimezoneResolver::FALLBACK,
                new UserTimezoneResolver($configured)->resolve(null)->getName(),
                sprintf('configured default %s', var_export($configured, true)),
            );
        }

        self::assertNotSame('UTC', UserTimezoneResolver::FALLBACK);
    }

    /**
     * A user who genuinely wants UTC can have it. The point was never that UTC
     * is a bad zone, only that it is a bad *default*.
     */
    public function testUtcRemainsChoosable(): void
    {
        $user = new User();
        $user->timezone = 'UTC';

        self::assertSame('UTC', new UserTimezoneResolver('Europe/Berlin')->resolve($user)->getName());
    }
}
