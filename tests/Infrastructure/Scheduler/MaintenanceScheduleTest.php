<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Scheduler;

use App\Infrastructure\Scheduler\MaintenanceSchedule;
use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Service\Mail\InboxProbeInterval;
use App\Service\Monitoring\WorkerRestartSignal;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;

/**
 * What the scheduler is told to run and how often, for the entries whose
 * cadence is the feature.
 *
 * Nothing else exercises the schedule: a command that is written, tested and
 * never scheduled is a command that never runs, with every test green.
 */
final class MaintenanceScheduleTest extends TestCase
{
    private const string ENV = 'MAIL_PROBE_INTERVAL_SECONDS';

    private string $secretsFile;

    /** @var array{server: mixed, env: mixed} */
    private array $original;

    protected function setUp(): void
    {
        $this->secretsFile = sys_get_temp_dir().'/plmail-schedule-'.bin2hex(random_bytes(6)).'/generated.env';
        mkdir(\dirname($this->secretsFile), 0700);

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

    /**
     * Mail from an OAuth account reaches plMail within a minute of the probe
     * seeing it, and the probe sees it within 30 s of its arriving, so the
     * probe is asked every 30 s unless an administrator chose otherwise. The
     * quarter-hourly sync stays beside it as the backstop for everything the
     * probe cannot see (spam, mail filtered past the inbox, flag changes).
     */
    public function testTheInboxProbeRunsEveryThirtySecondsAndTheFullSyncStaysQuarterHourly(): void
    {
        $triggers = $this->triggersByCommand();

        self::assertSame('every 30 seconds', $triggers['app:mail:probe'] ?? null);
        self::assertSame('*/15 * * * *', $triggers['app:mail:sync'] ?? null);
    }

    public function testTheInboxProbeFollowsTheConfiguredInterval(): void
    {
        file_put_contents($this->secretsFile, "MAIL_PROBE_INTERVAL_SECONDS=45\n");

        $triggers = $this->triggersByCommand();

        // The configured entry, and the sweep beside it untouched: the second
        // assertion is what shows the first is the probe's own and not every
        // trigger in the schedule picking the value up.
        self::assertSame('every 45 seconds', $triggers['app:mail:probe'] ?? null);
        self::assertSame('*/15 * * * *', $triggers['app:mail:sync'] ?? null);
    }

    /**
     * @return array<string, string> command line => the trigger's own description (a cron expression, or "every N seconds")
     */
    private function triggersByCommand(): array
    {
        $interval = new InboxProbeInterval(
            new GeneratedSecretsFile($this->secretsFile),
            new WorkerRestartSignal(new ArrayAdapter()),
            new NullLogger(),
        );

        $schedule = (new MaintenanceSchedule(new ArrayAdapter(), $interval))->getSchedule();

        $triggers = [];

        foreach ($schedule->getRecurringMessages() as $recurring) {
            $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable());

            foreach ($recurring->getMessages($context) as $message) {
                if ($message instanceof RunCommandMessage) {
                    $triggers[$message->input] = (string) $recurring->getTrigger();
                }
            }
        }

        return $triggers;
    }
}
