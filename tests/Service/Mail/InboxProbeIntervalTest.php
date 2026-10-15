<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\InboxProbeInterval;
use App\Service\Monitoring\WorkerRestartSignal;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * How often the inbox probe asks, and what that implies for one run.
 *
 * The figures are pinned by hand rather than read back from the class: the
 * default is 30 s, the bounds are 15 s and 900 s (the quarter-hourly sweep
 * already covers everything beyond that), and a run's time budget is the
 * interval less one 10 s request timeout — so 20 s, 5 s and 890 s.
 */
final class InboxProbeIntervalTest extends TestCase
{
    private const string ENV = 'MAIL_PROBE_INTERVAL_SECONDS';

    private string $secretsFile;

    private ArrayAdapter $signalPool;

    /** @var array{server: mixed, env: mixed} */
    private array $original;

    protected function setUp(): void
    {
        $this->secretsFile = sys_get_temp_dir().'/plmail-probeinterval-'.bin2hex(random_bytes(6)).'/generated.env';
        mkdir(\dirname($this->secretsFile), 0700);

        $this->signalPool = new ArrayAdapter();

        $this->original = [
            'server' => $_SERVER[self::ENV] ?? null,
            'env'    => $_ENV[self::ENV] ?? null,
        ];

        unset($_SERVER[self::ENV], $_ENV[self::ENV]);
    }

    protected function tearDown(): void
    {
        foreach (['server' => &$_SERVER, 'env' => &$_ENV] as $key => &$bag) {
            if (null === $this->original[$key]) {
                unset($bag[self::ENV]);
            } else {
                $bag[self::ENV] = $this->original[$key];
            }
        }

        if (is_file($this->secretsFile)) {
            unlink($this->secretsFile);
        }

        if (is_dir(\dirname($this->secretsFile))) {
            rmdir(\dirname($this->secretsFile));
        }
    }

    public function testNothingConfiguredMeansThirtySeconds(): void
    {
        self::assertSame(30, $this->interval()->seconds());
        self::assertNull($this->interval()->stored());
        self::assertFalse($this->interval()->isPinnedByEnvironment());
    }

    public function testAStoredValueIsUsed(): void
    {
        file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS=45\n");

        self::assertSame(45, $this->interval()->seconds());
        self::assertSame(45, $this->interval()->stored());
    }

    public function testARealEnvironmentValueWinsOverTheStoredFile(): void
    {
        file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS=45\n");
        $_SERVER[self::ENV] = '20';

        self::assertSame(20, $this->interval()->seconds());
        // Not a pin for the page: with an entry on file it cannot tell this
        // from the entrypoint's export of an earlier save (see
        // isPinnedByEnvironment()). What is in force is still the environment's.
        self::assertFalse($this->interval()->isPinnedByEnvironment());
        // What is on file is still reported as such, so the form can show both.
        self::assertSame(45, $this->interval()->stored());
    }

    public function testAValueSavedFromTheScreenIsNotAPinOnceTheEntrypointHasExportedIt(): void
    {
        // The entrypoint exports every line of the generated file into the
        // process environment, and config/bootstrap_generated_secrets.php folds
        // the same lines into $_SERVER, so the value this page saved comes back
        // as "set in the environment" at the next start. That is the page's
        // own value, not an operator's pin, and calling it one locked the
        // screen against its own setting.
        file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS=45
");
        $_SERVER[self::ENV] = '45';

        self::assertFalse($this->interval()->isPinnedByEnvironment());
        self::assertSame(45, $this->interval()->seconds());
    }

    public function testASecondSaveBeforeTheRestartIsNotAPinEither(): void
    {
        // 15 was saved and exported at the last start; 45 is saved now and the
        // web process still holds 15. The two are indistinguishable from an
        // operator's pin that happens to differ from the file, so the file
        // having an entry at all settles it: the page wrote that entry.
        file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS=45
");
        $_SERVER[self::ENV] = '15';

        self::assertFalse($this->interval()->isPinnedByEnvironment());
    }

    public function testAnEnvironmentValueWithNothingSavedIsAPin(): void
    {
        // The presence companion of the two above: with no entry on file there
        // is nothing the environment value could have been exported from.
        $_SERVER[self::ENV] = '20';

        self::assertTrue($this->interval()->isPinnedByEnvironment());
        self::assertSame(20, $this->interval()->seconds());
    }

    public function testAnEmptyEnvironmentValueCountsAsAbsent(): void
    {
        // The TrueNAS compose passes every unconfigured value through as an
        // empty string; that must not shadow what was saved from the admin page.
        file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS=45\n");
        $_SERVER[self::ENV] = '';

        self::assertSame(45, $this->interval()->seconds());
        self::assertFalse($this->interval()->isPinnedByEnvironment());
    }

    public function testAValueOutsideTheBoundsIsClampedRatherThanTrusted(): void
    {
        // The scheduler builds its trigger from this at boot, so a typo in a
        // compose file must not become "every second" or "never".
        file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS=5\n");
        self::assertSame(15, $this->interval()->seconds());

        file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS=5000\n");
        self::assertSame(900, $this->interval()->seconds());

        // The edges themselves are allowed, which the clamps above would also
        // satisfy if they were off by one.
        file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS=15\n");
        self::assertSame(15, $this->interval()->seconds());

        file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS=900\n");
        self::assertSame(900, $this->interval()->seconds());
    }

    public function testSomethingThatIsNotAWholeNumberFallsBackToTheDefault(): void
    {
        foreach (['soon', '30.5', '-20', '0'] as $garbage) {
            file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS={$garbage}\n");

            self::assertSame(30, $this->interval()->seconds(), $garbage);
            self::assertNull($this->interval()->stored(), $garbage);
        }
    }

    public function testSavingWritesTheFileAndAsksTheWorkersToRestart(): void
    {
        $signal = new WorkerRestartSignal($this->signalPool);

        self::assertNull($signal->requestedAt(), 'Nothing has asked for a restart yet.');

        $this->interval()->save(60);

        self::assertStringContainsString('MAIL_PROBE_INTERVAL_SECONDS=60', (string) file_get_contents($this->secretsFile));
        self::assertSame(60, $this->interval()->seconds());
        // The scheduler reads the interval when it boots, so a save that did
        // not restart the workers would be acknowledged and then ignored.
        self::assertNotNull($signal->requestedAt());
    }

    public function testSavingSomethingOutsideTheBoundsThrowsAndWritesNothing(): void
    {
        foreach ([14, 901, 0, -1] as $bad) {
            try {
                $this->interval()->save($bad);
                self::fail("save({$bad}) should have thrown");
            } catch (\InvalidArgumentException) {
                self::assertFileDoesNotExist($this->secretsFile, (string) $bad);
            }
        }

        $this->interval()->save(15);
        $this->interval()->save(900);

        self::assertSame(900, $this->interval()->seconds());
    }

    public function testARunsBudgetIsTheIntervalLessOneRequestTimeout(): void
    {
        // 30 - 10, 15 - 10, 900 - 10.
        self::assertSame(20, $this->withStored(30)->budgetSeconds());
        self::assertSame(5, $this->withStored(15)->budgetSeconds());
        self::assertSame(890, $this->withStored(900)->budgetSeconds());
    }

    public function testTheBudgetPlusOneRequestTimeoutFitsTheIntervalAtEveryBound(): void
    {
        // The clients declare their own timeout constant, so the sum is
        // checked against what they really use and not only against
        // REQUEST_TIMEOUT_SECONDS: either can move on its own.
        //
        // What this does NOT cover: a Gmail probe may read up to five pages
        // (PROBE_MAX_PAGES) and a Graph request is retried twice by its client,
        // so one account can still outlast the interval. An account that times
        // out is paused for five minutes (InboxProbe::FAILURE_PAUSE_SECONDS),
        // which keeps that to one slow run rather than one per tick.
        foreach ([GmailApiClient::class, GraphApiClient::class] as $client) {
            $timeout = (new \ReflectionClassConstant($client, 'PROBE_TIMEOUT_SECONDS'))->getValue();

            self::assertSame(10.0, $timeout, $client);
            self::assertSame(InboxProbeInterval::REQUEST_TIMEOUT_SECONDS, $timeout, $client);
        }

        foreach ([15, 30, 900] as $seconds) {
            $interval = $this->withStored($seconds);

            self::assertSame($seconds, $interval->seconds());
            self::assertLessThanOrEqual(
                $seconds,
                $interval->budgetSeconds() + InboxProbeInterval::REQUEST_TIMEOUT_SECONDS,
                "{$seconds} s",
            );
        }
    }

    private function withStored(int $seconds): InboxProbeInterval
    {
        file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS={$seconds}\n");

        return $this->interval();
    }

    private function interval(): InboxProbeInterval
    {
        return new InboxProbeInterval(
            new GeneratedSecretsFile($this->secretsFile),
            new WorkerRestartSignal($this->signalPool),
            new NullLogger(),
        );
    }
}
