<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Domain\DTO\Ai\BackfillProgress;
use App\Domain\Enum\Ai\BackfillPauseReason;
use App\Domain\Enum\Ai\BackfillStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The admin AI panel renders each of its states, and renders them differently.
 *
 * WHY A RENDER TEST AND NOT A BROWSER ONE
 * ───────────────────────────────────────
 * The states this file walks are states a browser cannot easily be put into: a
 * model that has spilled onto the CPU needs a host with not enough VRAM, and
 * an unreachable host needs the host switched off mid-suite. They are also the
 * states that matter most — a panel that quietly renders nothing when a model
 * is half on the CPU is worse than no panel, because that is the one thing it
 * exists to catch.
 *
 * `lint:twig` cannot catch any of this: it parses, and every failure here is a
 * runtime one — a method called on an array, a null reaching |date, an enum
 * read as a string.
 *
 * The assertions deliberately avoid translated copy. The keys are added to
 * translations/ separately, and a test that asserted on the English would pass
 * only until somebody wrote the German.
 */
final class AiPerformancePanelRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->twig = self::getContainer()->get(Environment::class);
    }

    /** State 1: the master switch is off. No numbers, no alarm. */
    public function testTheOffStateSaysNothingAboutAHostItNeverAsked(): void
    {
        $html = $this->render(self::panel(['enabled' => false, 'ready' => false, 'configured' => false]));

        self::assertStringNotContainsString('<table', $html, 'nothing was measured, so there is nothing to tabulate');
        self::assertStringNotContainsString('http://', $html, 'no host was asked');
    }

    /** State 2: reachable is false. Calm, and no table of zeros. */
    public function testAnUnreachableHostRendersWithoutTheTelemetry(): void
    {
        $html = $this->render(self::panel([
            'host' => ['baseUrl' => 'http://10.0.0.5:11434', 'reachable' => false, 'version' => null, 'reason' => 'unreachable'],
        ]));

        self::assertStringNotContainsString('<table', $html);
        self::assertStringNotContainsString('bg-amber-500', $html, 'a host that is off is not a fault to be coloured');
    }

    /** State 3: reachable, nothing resident. */
    public function testNothingResidentRendersNoModelRows(): void
    {
        $html = $this->render(self::panel(['loaded' => [], 'coldLoadMs' => 13000.0]));

        // The quantisation is printed raw, and only for a model that is
        // actually in memory — the configured model NAMES are listed either
        // way, higher up, which is why they are the wrong thing to assert on.
        self::assertStringNotContainsString('Q4_K_M', $html);
        self::assertStringNotContainsString('bg-amber-500', $html);
    }

    /** State 4: resident and whole. The good one. */
    public function testAModelWhollyOnTheGpuRendersWithoutAWarningBar(): void
    {
        $html = $this->render(self::panel(['loaded' => [self::model(true)]]));

        // The size is inside a translated sentence, so it is deliberately not
        // asserted on: an untranslated key renders without its placeholders,
        // and this suite must pass both before and after the copy lands.
        self::assertStringContainsString('Q4_K_M', $html, 'the resident model is listed');
        self::assertStringNotContainsString('bg-amber-500', $html);
    }

    /**
     * State 5: resident, and part of it is on the CPU.
     *
     * The failure the whole panel exists for. The split bar is the assertion
     * because it is the part that carries the weight — a number in a table
     * would be read past.
     */
    public function testAModelSplitOntoTheCpuGetsVisibleWeight(): void
    {
        $html = $this->render(self::panel(['loaded' => [self::model(false)], 'anyPartial' => true]));

        self::assertStringContainsString('bg-amber-500', $html);
        self::assertStringContainsString('width: 62%', $html, 'the GPU share is drawn, not just stated');
        self::assertStringContainsString('width: 38%', $html, 'and so is what spilled');
    }

    /** State 6: no calls recorded. An empty sentence, never a table of zeros. */
    public function testAnEmptyWindowRendersNoTable(): void
    {
        $html = $this->render(self::panel(['hasHistory' => false, 'perFeature' => [], 'latest' => null]));

        self::assertStringNotContainsString('<table', $html);
        self::assertStringNotContainsString('NaN', $html);
    }

    public function testAWindowWithHistoryRendersTheTable(): void
    {
        $html = $this->render(self::panel());

        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('120', $html, 'the call count is on screen');
    }

    /** The backfill card: a percentage, a bar, and controls that reflect state. */
    public function testTheBackfillCardRendersItsProgressAndControls(): void
    {
        $html = $this->render(self::panel());

        self::assertStringContainsString('42.5%', $html);
        self::assertStringContainsString('admin--ai-status#start', $html);
        self::assertStringContainsString('admin--ai-status#pause', $html);
    }

    /**
     * An estimate is withheld until the rate means something, and its absence
     * must not render as an empty gap or a zero.
     */
    public function testAnUnsettledRateShowsNoEstimate(): void
    {
        $html = $this->render(self::panel(['backfill' => self::backfill(['etaSeconds' => null])]));

        self::assertStringNotContainsString('admin.ai.backfill.eta"', $html);
        self::assertStringNotContainsString('NaN', $html);
    }

    /** @param array<string, mixed> $panel */
    private function render(array $panel): string
    {
        return $this->twig->render('admin/ai/_performance.html.twig', ['panel' => $panel]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function panel(array $overrides = []): array
    {
        return [
            'enabled'    => true,
            'configured' => true,
            'ready'      => true,
            'features'   => ['search' => true, 'categorise' => false, 'writing' => true, 'summary' => false],
            'host'       => ['baseUrl' => 'http://10.0.0.5:11434', 'reachable' => true, 'version' => '0.6.2', 'reason' => null],
            'models'     => [
                ['role' => 'chat', 'name' => 'qwen2.5:32b', 'installed' => true, 'resident' => true],
                ['role' => 'embedding', 'name' => 'nomic-embed-text', 'installed' => true, 'resident' => false],
            ],
            'loaded'     => [self::model(true)],
            'anyPartial' => false,
            'latest'     => [
                'feature' => 'writing_help', 'model' => 'qwen2.5:32b', 'succeeded' => true,
                'errorKind' => null, 'genTokensPerSecond' => 18.4, 'loadMs' => 4.0, 'at' => '2026-08-27 09:00:00',
            ],
            'window'     => 'day',
            'perFeature' => [[
                'bucket' => 'writing_help', 'calls' => 120, 'errors' => 0, 'coldLoads' => 2,
                'genTokensPerSecondP50' => 18.4, 'genTokensPerSecondP95' => 21.0,
                'promptTokensPerSecondP50' => 320.0, 'loadMsP50' => 6.0, 'share' => 100,
            ]],
            'perModel'   => [],
            'hasHistory' => true,
            'coldLoadMs' => 13000.0,
            'backfill'   => self::backfill(),
            'checkedAt'  => '2026-08-27T09:00:00+00:00',
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private static function model(bool $whole): array
    {
        return [
            'name'          => 'qwen2.5:32b',
            'sizeBytes'     => 21_798_711_296,
            'sizeLabel'     => '20.3 GiB',
            'vramBytes'     => $whole ? 21_798_711_296 : 13_515_204_003,
            'vramLabel'     => $whole ? '20.3 GiB' : '12.6 GiB',
            'cpuLabel'      => $whole ? null : '7.7 GiB',
            'fullyOnGpu'    => $whole,
            'gpuPercent'    => $whole ? 100 : 62,
            'unloadsIn'     => 240,
            'parameterSize' => '32.8B',
            'quantisation'  => 'Q4_K_M',
            'contextLength' => 8192,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private static function backfill(array $overrides = []): BackfillProgress
    {
        $defaults = [
            'status'          => BackfillStatus::Running,
            'pauseReason'     => null,
            'model'           => 'nomic-embed-text',
            'embedded'        => 4_250,
            'eligible'        => 10_000,
            'failures'        => 3,
            'ratePerSecond'   => 8.2,
            'etaSeconds'      => 700,
            'startedAt'       => new \DateTimeImmutable('2026-08-27 08:00:00'),
            'lastProgressAt'  => new \DateTimeImmutable('2026-08-27 09:00:00'),
            'finishedAt'      => null,
            'stalled'         => false,
            'canStart'        => false,
            'canPause'        => true,
            'canResume'       => false,
            'blockedReason'   => null,
            'batchSize'       => 10,
            'pauseMs'         => 2000,
            'cooldownSeconds' => 90,
        ];

        $values = [...$defaults, ...$overrides];

        return new BackfillProgress(...$values);
    }

    /** Kept so the enum import is used by a paused-state fixture when one is added. */
    public function testPauseReasonsAreDistinguishable(): void
    {
        self::assertTrue(BackfillPauseReason::Interactive->resumesItself());
        self::assertFalse(BackfillPauseReason::Operator->resumesItself());
    }
}
