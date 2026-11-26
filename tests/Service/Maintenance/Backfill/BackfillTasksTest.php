<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\Backfill;

use App\Domain\DTO\Maintenance\BackfillEntry;
use App\Service\Maintenance\Backfill\BackfillCatalogue;
use App\Service\Maintenance\Backfill\BackfillTasks;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every registered backfill can be shown on Admin → Backfills, in the order
 * the page promises.
 *
 * Against the real container, because the claim is about the tasks that are
 * actually registered: a task added next month without its date or its text
 * should fail here, not render as a raw translation key in front of an
 * administrator deciding whether to run it.
 */
final class BackfillTasksTest extends KernelTestCase
{
    public function testTheNewestTaskComesFirst(): void
    {
        self::bootKernel();

        $tasks = array_values(self::getContainer()->get(BackfillTasks::class)->all());

        self::assertNotEmpty($tasks);

        for ($i = 1; $i < count($tasks); ++$i) {
            self::assertGreaterThanOrEqual(
                $tasks[$i]->getAddedOn(),
                $tasks[$i - 1]->getAddedOn(),
                sprintf('%s is listed before %s but is older', $tasks[$i - 1]->getName(), $tasks[$i]->getName()),
            );
        }
    }

    /**
     * A date in the future would pin a task to the top of the list and mark it
     * New for longer than a month: the shape of a typo in the year.
     */
    public function testNoTaskIsDatedInTheFuture(): void
    {
        self::bootKernel();

        $tomorrow = new DateTimeImmutable('tomorrow');

        foreach (self::getContainer()->get(BackfillTasks::class)->all() as $name => $task) {
            self::assertLessThan($tomorrow, $task->getAddedOn(), sprintf('%s is dated in the future', $name));
        }
    }

    /**
     * The summary in the list and the explanation in the detail, and for a
     * task that is not safe to run twice, the warning saying why. Checked in
     * English only: CatalogueParityTest holds the other catalogues to it.
     */
    public function testEveryTaskSaysWhatItDoes(): void
    {
        self::bootKernel();

        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        $catalogue = $translator->getCatalogue('en');
        $entries   = self::getContainer()->get(BackfillCatalogue::class)->entries(new DateTimeImmutable());

        self::assertNotEmpty($entries);

        foreach ($entries as $entry) {
            self::assertInstanceOf(BackfillEntry::class, $entry);

            $parts = true === $entry->safeToRunAgain
                ? ['summary', 'explanation']
                : ['summary', 'explanation', 'repeat_warning'];

            foreach ($parts as $part) {
                self::assertTrue(
                    $catalogue->has(sprintf('%s.%s', $entry->translationKey, $part)),
                    sprintf('%s has no %s in messages.en.yaml', $entry->name, $part),
                );
            }
        }
    }
}
