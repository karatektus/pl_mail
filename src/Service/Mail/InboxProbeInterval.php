<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Service\Monitoring\WorkerRestartSignal;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * How often the inbox probe asks Gmail and Outlook whether mail has arrived,
 * and the time one run of it may take because of that.
 *
 * WHERE THE VALUE COMES FROM. `MAIL_PROBE_INTERVAL_SECONDS` in the environment
 * wins; failing that, the value saved from Admin → Address, which lives in the
 * generated-config file beside APP_PUBLIC_URL (see PublicUrlSetting, whose
 * reasons for that place and for the precedence apply unchanged); failing that,
 * DEFAULT_SECONDS. An empty environment value counts as absent, because the
 * TrueNAS compose passes every unconfigured setting through as one.
 *
 * WHY IT IS BOUNDED. MaintenanceSchedule builds a trigger from it when the
 * scheduler boots, so an unchecked value would become a schedule: a typo of 1
 * would ask every provider every second, and the probe shares the scheduler's
 * one worker with every other job. Below MIN_SECONDS a run's budget (the
 * interval less one request timeout) is too small for a second account; above
 * MAX_SECONDS the quarter-hourly sweep is already as fast. Out-of-range values
 * from the environment or the file are clamped rather than refused, because
 * something has to start; anything that is not a whole number is ignored for
 * the default. save() is stricter and throws, since the only caller validates
 * first and a bad value reaching it is a bug, not an input.
 *
 * A CHANGE TAKES THE WORKERS RESTARTING, which save() asks for: the scheduler
 * reads this once, when it boots. Making the running schedule follow the file
 * was left out for the reason PublicUrlSetting gives for its own value.
 */
final readonly class InboxProbeInterval
{
    public const string ENV = 'MAIL_PROBE_INTERVAL_SECONDS';

    public const int DEFAULT_SECONDS = 30;

    public const int MIN_SECONDS = 15;

    public const int MAX_SECONDS = 900;

    /**
     * The longest one provider request may take before the probe gives up on
     * it. GmailApiClient and GraphApiClient take their own timeouts from here.
     */
    public const float REQUEST_TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private GeneratedSecretsFile $config,
        private WorkerRestartSignal $workerRestart,
        private LoggerInterface $logger,
    ) {
    }

    /** The interval in force: environment, else the saved value, else the default. */
    public function seconds(): int
    {
        $fromEnvironment = self::parse($this->environmentValue());

        if (null !== $fromEnvironment) {
            return self::clamp($fromEnvironment);
        }

        return self::clamp($this->stored() ?? self::DEFAULT_SECONDS);
    }

    /** What is saved on file, whatever the environment says; null when nothing usable is. */
    public function stored(): ?int
    {
        return self::parse($this->config->read()[self::ENV] ?? null);
    }

    /**
     * Whether the environment sets it, in which case the admin page cannot
     * change what is in force and says so.
     *
     * An environment value counts as a pin only while the generated file has no
     * entry of its own. The entrypoint exports every line of that file into the
     * process environment, and config/bootstrap_generated_secrets.php folds the
     * same lines into $_SERVER, so a value this page saved comes back as "set
     * in the environment" at the next start; reading that as a pin locked the
     * screen against its own setting. With an entry on file, an environment
     * value is either that export or, after a second save, the export of the
     * previous value still held by a process that has not restarted yet, and
     * the two cannot be told apart from here (ConfigBackupEnvironment::isShadowed
     * compares the live value with the file's, which is right for a restore and
     * wrong here, where the page rewrites the file while processes run).
     *
     * WHAT THIS DOES NOT COVER: a pin added to the compose file after a value
     * was saved. seconds() still lets the environment win, so what is in force
     * is right, but the page offers a form whose save changes nothing until the
     * saved line is removed from the file.
     */
    public function isPinnedByEnvironment(): bool
    {
        return null !== self::parse($this->environmentValue()) && null === $this->stored();
    }

    /**
     * The point in a run past which no further account is started: the
     * interval less one request timeout, so the last account to start can
     * finish its FIRST request before the next run is due.
     *
     * Not a bound on a whole run: a Gmail probe may read several pages and a
     * Graph request is retried by its client, so one account can still outlast
     * the interval. See MailProbeCommand for what keeps that to one slow run.
     */
    public function budgetSeconds(): int
    {
        return max(1, $this->seconds() - (int) self::REQUEST_TIMEOUT_SECONDS);
    }

    /**
     * @throws \InvalidArgumentException when $seconds is outside MIN_SECONDS..MAX_SECONDS
     */
    public function save(int $seconds): void
    {
        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'The probe interval must be between %d and %d seconds, %d given.',
                self::MIN_SECONDS,
                self::MAX_SECONDS,
                $seconds,
            ));
        }

        $this->config->set(self::ENV, (string) $seconds);
        $this->restartWorkers();
    }

    private function environmentValue(): ?string
    {
        $value = $_SERVER[self::ENV] ?? $_ENV[self::ENV] ?? null;

        return \is_string($value) ? $value : null;
    }

    private static function parse(?string $value): ?int
    {
        $value = trim((string) $value);

        // Digits only: "30.5", "-20" and "soon" are not intervals, and "0" is
        // not a usable one either.
        if ('' === $value || 1 !== preg_match('/^[0-9]{1,9}$/', $value) || 0 === (int) $value) {
            return null;
        }

        return (int) $value;
    }

    private static function clamp(int $seconds): int
    {
        return min(self::MAX_SECONDS, max(self::MIN_SECONDS, $seconds));
    }

    /**
     * The scheduler is a long-running worker and reads the interval once, at
     * boot. A nudge that fails is not worth failing the save over: the value
     * is on file, and the workers recycle hourly regardless.
     */
    private function restartWorkers(): void
    {
        try {
            $this->workerRestart->request();
        } catch (Throwable $e) {
            $this->logger->warning('Saved the probe interval but could not signal the workers to restart.', [
                'exception' => $e,
            ]);
        }
    }
}
