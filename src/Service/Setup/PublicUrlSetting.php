<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Service\Monitoring\WorkerRestartSignal;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where plMail thinks it lives, as seen from outside.
 *
 * `APP_PUBLIC_URL` is the address Google and Microsoft call back to with push
 * notifications, so it cannot be inferred at the moment it is needed — the
 * worker building a subscription has no request to read a hostname from. It has
 * no sensible default either, which is why it is asked for during setup rather
 * than left to a placeholder nobody notices until push silently never arrives.
 *
 * Stored in the generated-config file, the one place a running container can
 * write that every other service reads. An APP_PUBLIC_URL supplied through the
 * environment still wins, so a deployment that sets it is untouched.
 *
 * Changeable after setup, from Admin → Address: an install gets a domain, moves
 * behind a different proxy, or is restored from a backup taken somewhere else,
 * and the address in that backup is the old machine's. A change takes a restart
 * to reach processes that are already running. Making it live was considered
 * and left out — the entrypoint exports this file into every container's
 * environment at start, and telling a stale export from a deliberate pin needs
 * more machinery than a restart costs.
 */
final readonly class PublicUrlSetting
{
    /**
     * Where the hub answers on the app's own origin — the suffix
     * config/bootstrap_generated_secrets.php derives MERCURE_PUBLIC_URL with.
     */
    private const string HUB_PATH = '/.well-known/mercure';

    public function __construct(
        private GeneratedSecretsFile $config,
        private WorkerRestartSignal $workerRestart,
        private LoggerInterface $logger,
    ) {
    }

    public function save(string $url): void
    {
        $url = rtrim(trim($url), '/');

        // A hub address on file that is only the previous public address with
        // the hub's path on it was never a decision of its own — a restored
        // backup carries one. Left in place it keeps browsers subscribing at
        // the address that was just replaced, and live updates stay dead while
        // everything else has moved. One that points anywhere else is somebody's
        // separate hub, and stays.
        $previous = $this->stored();

        if (null !== $previous && $previous.self::HUB_PATH === trim($this->config->read()['MERCURE_PUBLIC_URL'] ?? '')) {
            $this->config->remove(['MERCURE_PUBLIC_URL']);
        }

        $this->config->set('APP_PUBLIC_URL', $url);

        // The web process picks the new value up on the next request; the
        // workers are long-running and would otherwise hold the old one until
        // they recycled, which is exactly when push subscriptions get built.
        //
        // A nudge that fails is not worth failing an install over: the address
        // is already saved, and the workers recycle hourly regardless.
        try {
            $this->workerRestart->request();
        } catch (Throwable $e) {
            $this->logger->warning('Saved APP_PUBLIC_URL but could not signal the workers to restart.', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * The effective public URL right now, or null while none is configured.
     *
     * Same precedence as everywhere else — a real environment value wins, the
     * setup-written file is the fallback — but resolved at call time rather
     * than frozen into a service at container build. That distinction is what
     * lets a long-running worker see a URL the admin saved after the worker
     * booted: the file is re-read here, not snapshotted.
     */
    public function current(): ?string
    {
        $env = trim((string) ($_SERVER['APP_PUBLIC_URL'] ?? $_ENV['APP_PUBLIC_URL'] ?? ''));

        if ('' !== $env) {
            return rtrim($env, '/');
        }

        $stored = trim($this->config->read()['APP_PUBLIC_URL'] ?? '');

        return '' === $stored ? null : rtrim($stored, '/');
    }

    /**
     * What is on file, whatever the running processes were started with.
     *
     * Differs from current() in exactly two situations, and Admin → Address
     * shows both values because it cannot tell them apart: a saved address
     * still waiting for its restart, since the entrypoint exports the file into
     * the environment once, at container start; or an APP_PUBLIC_URL set in the
     * compose file, which wins over the file for good.
     */
    public function stored(): ?string
    {
        $stored = trim($this->config->read()['APP_PUBLIC_URL'] ?? '');

        return '' === $stored ? null : rtrim($stored, '/');
    }

    /**
     * The address this request arrived on, as the best guess to offer during
     * setup. Correct whenever plMail is reached the same way its users reach
     * it, which on first run it is — someone is looking at it in a browser.
     */
    public function guessFrom(string $schemeAndHost): string
    {
        return rtrim($schemeAndHost, '/');
    }
}
