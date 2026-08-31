<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Backup\ConfigBackupDatabase;
use App\Service\Backup\ConfigBackupUsers;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The backup has to fail when it stops being complete, and it could not.
 *
 * A backup's characteristic defect is omission: not a wrong value written, but
 * a right value never written at all. Every guard this feature had pointed the
 * other way. The env test builds its expectation by calling the very constant
 * it is testing, so adding a variable and forgetting the inventory leaves it
 * green. The users test is fourteen assertArrayNotHasKey calls, which fail if
 * somebody ADDS a field and never if one goes missing. The document-version
 * assertion compares a constant against its own echo.
 *
 * So five user settings, two account settings and an entire admin-configured
 * table shipped over four months without one test going red, and a person
 * restoring an install got their signatures, their insight choices and their
 * whole AI configuration silently dropped — silently because a section that
 * does not exist produces no row on the review page to warn about.
 *
 * These two tests are the direction that was missing. Neither compares the
 * backup against itself: each compares it against the application, and fails
 * closed. Adding a setting or an admin-configured table is then a red suite
 * until somebody has decided whether it travels — a cheap decision, and the
 * only expensive part was never noticing it had to be made.
 *
 * They deliberately do not assert WHAT the decision should be. An exclusion is
 * as good an answer as an inclusion, as long as it is written down beside the
 * reason, which is why the excluded lists are maps of key to reason rather than
 * bare lists.
 */
final class ConfigBackupCompletenessTest extends TestCase
{
    /**
     * Every `User::SETTING_*` is carried, or excluded on the record.
     */
    public function testEveryUserSettingIsEitherCarriedOrExcludedWithAReason(): void
    {
        $this->assertEveryConstantIsAccountedFor(
            User::class,
            ConfigBackupUsers::USER_SETTINGS,
            ConfigBackupUsers::EXCLUDED_USER_SETTINGS,
            'ConfigBackupUsers::USER_SETTINGS',
        );
    }

    /**
     * Every `Account::SETTING_*`, likewise. This is the list that lost
     * `compose.signature` — on the instance the bug was found on, the only
     * account setting in use was the one the backup did not carry, so every
     * export it produced held an empty settings bag for every account.
     */
    public function testEveryAccountSettingIsEitherCarriedOrExcludedWithAReason(): void
    {
        $this->assertEveryConstantIsAccountedFor(
            Account::class,
            ConfigBackupUsers::ACCOUNT_SETTINGS,
            ConfigBackupUsers::EXCLUDED_ACCOUNT_SETTINGS,
            'ConfigBackupUsers::ACCOUNT_SETTINGS',
        );
    }

    /**
     * Every admin-configured singleton is in the database section.
     *
     * The section carried three keys and the install had five things an
     * administrator configures. `AiSettings` — the model host, both model
     * names, the reverse-proxy token, the feature flags and every rewritten
     * prompt — was simply not in it, and neither was the chosen log level.
     *
     * Named explicitly rather than discovered by scanning `src/Entity`, because
     * "is this a thing an administrator configures" is a judgement a reflection
     * pass cannot make: `ProcessHeartbeat` is a singleton too and belongs
     * nowhere near a backup. The judgement is cheap; making it is the point.
     * When a sixth admin form appears, add its table here and the test says
     * whether the backup learned about it.
     *
     * @return iterable<string, array{string}>
     */
    public static function adminConfiguredTables(): iterable
    {
        yield 'Firebase push credentials (Admin → Push)' => [ConfigBackupDatabase::FCM_CONFIG];
        yield 'mail provider registrations (Admin → Integrations)' => [ConfigBackupDatabase::MAIL_PROVIDERS];
        yield 'integration provider registrations (Admin → Integrations)' => [ConfigBackupDatabase::INTEGRATION_PROVIDERS];
        yield 'the assistant configuration (Admin → AI)' => [ConfigBackupDatabase::AI_SETTINGS];
        yield 'the chosen log level (Admin → Logs)' => [ConfigBackupDatabase::LOG_SETTINGS];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminConfiguredTables')]
    public function testTheDatabaseSectionCarriesEveryAdminConfiguredTable(string $key): void
    {
        self::assertContains(
            $key,
            ConfigBackupDatabase::SECTION_KEYS,
            sprintf(
                'The database section does not carry "%s". An administrator configured it through a '
                . 'form, and a restore would silently bring the install up without it — silently, '
                . 'because a section that does not exist produces no row on the review page.',
                $key,
            ),
        );
    }

    /**
     * @param list<string>          $carried
     * @param array<string, string> $excluded
     */
    private function assertEveryConstantIsAccountedFor(
        string $entity,
        array $carried,
        array $excluded,
        string $listName,
    ): void {
        $constants = array_filter(
            new ReflectionClass($entity)->getConstants(),
            static fn (string $name): bool => str_starts_with($name, 'SETTING_'),
            ARRAY_FILTER_USE_KEY,
        );

        self::assertNotEmpty($constants, $entity . ' declares no SETTING_ constants — has the naming changed?');

        foreach ($constants as $name => $key) {
            self::assertTrue(
                in_array($key, $carried, true) || array_key_exists((string) $key, $excluded),
                sprintf(
                    '%s::%s ("%s") is neither carried by a config backup nor excluded on the record. '
                    . 'Add it to %s so it survives a restore, or to the matching EXCLUDED_ list with '
                    . 'the reason it should not. Either is a fine answer; leaving it undecided is how '
                    . 'signatures were lost.',
                    $entity,
                    $name,
                    $key,
                    $listName,
                ),
            );
        }

        foreach (array_keys($excluded) as $key) {
            self::assertContains(
                $key,
                $constants,
                sprintf('"%s" is excluded but is no longer a %s setting — drop the stale entry.', $key, $entity),
            );
            self::assertNotContains(
                $key,
                $carried,
                sprintf('"%s" is in both the carried and the excluded list, which cannot both be true.', $key),
            );
        }

        foreach ($excluded as $key => $reason) {
            self::assertNotSame('', trim($reason), sprintf('"%s" is excluded with no reason given.', $key));
        }
    }
}
