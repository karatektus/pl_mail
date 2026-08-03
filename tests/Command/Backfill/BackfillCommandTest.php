<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\BackfillCommand;
use App\Command\Backfill\BackfillTaskInterface;
use PHPUnit\Framework\TestCase;
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
