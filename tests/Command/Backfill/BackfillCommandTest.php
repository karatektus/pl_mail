<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\BackfillCommand;
use App\Command\Backfill\BackfillTaskInterface;
use App\Command\Backfill\WindowedBackfillTaskInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The dispatcher in front of every one-off backfill.
 *
 * These tasks rewrite whole tables in place. The dispatcher is therefore the
 * one place that must never guess: an operator who mistypes a task name, or a
 * cron that invokes this with no argument at all, has to be turned away rather
 * than quietly handed whichever task happened to sort first. Every test below
 * is a variation on "did it run exactly the task that was asked for, or did it
 * correctly refuse".
 *
 * Built by hand rather than pulled from the container, so the registered task
 * set is fixed by the test instead of by whatever is autoconfigured today.
 */
final class BackfillCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        // --since is read against the global clock, which two tests pin.
        Clock::set(new NativeClock());
    }

    public function testItRunsTheTaskNamedOnTheCommandLine(): void
    {
        $wanted   = $this->task('wanted');
        $unwanted = $this->task('unwanted');

        $tester = $this->tester($wanted, $unwanted);
        $exit   = $tester->execute(['task' => 'wanted']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(1, $wanted->runs);
        self::assertSame(0, $unwanted->runs, 'Only the named task may run.');
    }

    public function testItHandsBackTheExitCodeOfTheTaskItRan(): void
    {
        $failing = $this->task('failing', Command::FAILURE);

        $tester = $this->tester($failing);

        // A backfill that half-finished must not report success to the shell
        // that scheduled it; the dispatcher adds nothing of its own here.
        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'failing']));
        self::assertSame(1, $failing->runs);
    }

    public function testItRefusesAnUnknownTaskInsteadOfRunningSomethingElse(): void
    {
        $real = $this->task('addresses');

        $tester = $this->tester($real);
        $exit   = $tester->execute(['task' => 'adresses']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(0, $real->runs);

        // The listing is the whole recovery path from a typo, so it has to name
        // what is actually registered.
        self::assertStringContainsString('addresses', $tester->getDisplay());
    }

    public function testItRefusesToChooseForItselfWhenThereIsNoOneToAsk(): void
    {
        $task = $this->task('rethread');

        $tester = $this->tester($task);

        // Cron and CI reach this command with no TTY. Picking a default here
        // would mean an unattended run rewriting a table nobody asked it to.
        $exit = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(0, $task->runs);
        self::assertStringContainsString('non-interactive', $tester->getDisplay());
    }

    public function testThePickerRunsTheTaskTheOperatorChose(): void
    {
        // Registration order is deliberately not alphabetical, because the
        // command ksorts and the answer is matched against the task key rather
        // than the position it was constructed in.
        $beta  = $this->task('beta');
        $alpha = $this->task('alpha');

        $tester = $this->tester($beta, $alpha);
        $tester->setInputs(['beta']);

        $exit = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(1, $beta->runs);
        self::assertSame(0, $alpha->runs);
    }

    /**
     * SymfonyStyle::choice hands back the key for an associative choice list,
     * but the command accepts the rendered label too. That fallback is there so
     * a Console upgrade cannot silently turn the picker into a no-op, and it is
     * only worth carrying if it actually works.
     */
    public function testThePickerAlsoAcceptsTheRenderedLabelItPrinted(): void
    {
        $task = $this->task('rethread');

        $tester = $this->tester($task);
        $tester->setInputs(['rethread — Description of rethread']);

        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => true]));
        self::assertSame(1, $task->runs);
    }

    public function testItSaysSoWhenNoTasksAreRegisteredAtAll(): void
    {
        $tester = $this->tester();

        // Nothing to do is not a failure — an install whose autoconfiguration
        // has dropped the tag would otherwise fail a deployment step for a
        // reason nobody could act on from the exit code.
        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'anything']));
        self::assertStringContainsString('No backfill tasks', $tester->getDisplay());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function tester(BackfillTaskInterface ...$tasks): CommandTester
    {
        return new CommandTester(new BackfillCommand($tasks));
    }

    // ── --since ───────────────────────────────────────────────────────────

    /**
     * The default is the point of the option: a task that re-reads mail and is
     * told nothing reads a day, not a mailbox.
     */
    public function testATaskThatReadsMailGetsTheLastDayUnlessToldOtherwise(): void
    {
        Clock::set(new MockClock('2026-10-08 16:00:00 UTC'));

        $events = $this->windowedTask('events');
        $tester = $this->tester($events);

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'events']));
        self::assertSame(['2026-10-07 16:00'], $events->windows);
        self::assertStringContainsString('2026-10-07 16:00', $tester->getDisplay());
        self::assertStringContainsString('--since=all', $tester->getDisplay(), 'How to widen it is said up front.');
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function windows(): iterable
    {
        yield 'hours'            => ['36h', '2026-10-07 04:00'];
        yield 'days'             => ['7d', '2026-10-01 16:00'];
        yield 'weeks'            => ['2w', '2026-09-24 16:00'];
        yield 'a date, from its start' => ['2026-09-01', '2026-09-01 00:00'];
        yield 'everything'       => ['all', null];
        yield 'case and spaces'  => [' ALL ', null];
    }

    #[DataProvider('windows')]
    public function testSinceIsReadAsAnAmountADateOrEverything(string $since, ?string $expected): void
    {
        Clock::set(new MockClock('2026-10-08 16:00:00 UTC'));

        $events = $this->windowedTask('events');

        self::assertSame(Command::SUCCESS, $this->tester($events)->execute(['task' => 'events', '--since' => $since]));
        self::assertSame([$expected], $events->windows);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonsense(): iterable
    {
        yield 'words'             => ['yesterday'];
        yield 'no unit'           => ['7'];
        yield 'zero'              => ['0d'];
        yield 'a unit not offered' => ['3m'];
        yield 'a day that does not exist' => ['2026-02-31'];
        yield 'empty'             => [''];
    }

    /**
     * Refused, and nothing run. Guessing here would decide how much of a
     * mailbox is re-read on the strength of a typo.
     */
    #[DataProvider('nonsense')]
    public function testAWindowItCannotReadRunsNothing(string $since): void
    {
        $events = $this->windowedTask('events');
        $tester = $this->tester($events);

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'events', '--since' => $since]));
        self::assertSame([], $events->windows);
        self::assertStringContainsString('not understood', $tester->getDisplay());
    }

    /**
     * A table repair has no window to honour. It still runs — over everything,
     * as it always did — and says that it ignored the option.
     */
    public function testATableRepairSaysItTakesNoWindow(): void
    {
        $repair = $this->task('recipients');
        $tester = $this->tester($repair);

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'recipients', '--since' => '7d']));
        self::assertSame(1, $repair->runs);
        self::assertStringContainsString('takes no --since', $tester->getDisplay());
    }

    public function testATableRepairGivenNoWindowSaysNothingAboutOne(): void
    {
        $tester = $this->tester($this->task('recipients'));
        $tester->execute(['task' => 'recipients']);

        self::assertStringNotContainsString('--since', $tester->getDisplay());
    }

    /**
     * @return WindowedBackfillTaskInterface&object{windows: list<?string>}
     */
    private function windowedTask(string $name): object
    {
        return new class ($name) implements WindowedBackfillTaskInterface {
            /** @var list<?string> what each run was told to read from, in UTC; null for everything */
            public array $windows = [];

            public function __construct(private readonly string $name) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return sprintf('Description of %s', $this->name);
            }

            public function run(SymfonyStyle $io): int
            {
                throw new \LogicException('A windowed task is run through runSince().');
            }

            public function runSince(SymfonyStyle $io, ?\DateTimeImmutable $since): int
            {
                $this->windows[] = $since?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i');

                return Command::SUCCESS;
            }
        };
    }

    /**
     * @return BackfillTaskInterface&object{runs: int}
     */
    private function task(string $name, int $exitCode = Command::SUCCESS): object
    {
        return new class ($name, $exitCode) implements BackfillTaskInterface {
            public int $runs = 0;

            public function __construct(
                private readonly string $name,
                private readonly int $exitCode,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return sprintf('Description of %s', $this->name);
            }

            public function run(SymfonyStyle $io): int
            {
                ++$this->runs;

                return $this->exitCode;
            }
        };
    }
}
