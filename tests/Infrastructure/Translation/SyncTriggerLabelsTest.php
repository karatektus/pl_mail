<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Translation;

use App\Domain\Enum\Mail\SyncTrigger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every way a message can have been brought in has a name in Admin → Performance.
 *
 * The slowest-mail table builds its label from the stored value —
 * `admin.performance.lag.slowest.by.<value>` — so a new SyncTrigger case with no
 * entry renders as the raw key, in every language, with nothing failing. It is
 * the one place a trigger's name is declared a second time, which is why it is
 * pinned here rather than trusted to be remembered.
 *
 * Read per locale rather than left to CatalogueParityTest, which only compares
 * the other catalogues with English: a case missing from English as well would
 * pass it.
 */
final class SyncTriggerLabelsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'en' => ['en'];
        yield 'de' => ['de'];
        yield 'en_PI' => ['en_PI'];
    }

    #[DataProvider('locales')]
    public function testEveryTriggerHasALabelInThePerformanceTable(string $locale): void
    {
        $tree = Yaml::parseFile(dirname(__DIR__, 3) . sprintf('/translations/messages.%s.yaml', $locale));

        self::assertIsArray($tree);

        $labels = $tree['admin']['performance']['lag']['slowest']['by'] ?? null;

        self::assertIsArray($labels, 'The table the labels belong to has moved; follow it.');

        foreach (SyncTrigger::cases() as $trigger) {
            self::assertArrayHasKey(
                $trigger->value,
                $labels,
                sprintf('SyncTrigger::%s has no label in messages.%s.yaml', $trigger->name, $locale),
            );
        }

        // The companion to the loop above: it must be able to fail. A row for a
        // value no trigger has is what the table shows when the column is empty.
        self::assertArrayHasKey('unknown', $labels);
    }
}
