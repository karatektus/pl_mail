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
 */
final readonly class PublicUrlSetting
{
    public function __construct(
        private GeneratedSecretsFile $config,
        private WorkerRestartSignal $workerRestart,
        private LoggerInterface $logger,
    ) {
    }

    public function save(string $url): void
    {
        $this->config->set('APP_PUBLIC_URL', rtrim(trim($url), '/'));

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
     * The address this request arrived on, as the best guess to offer during
     * setup. Correct whenever plMail is reached the same way its users reach
     * it, which on first run it is — someone is looking at it in a browser.
     */
    public function guessFrom(string $schemeAndHost): string
    {
        return rtrim($schemeAndHost, '/');
    }
}
