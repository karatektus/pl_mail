<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Restarts the web container by asking it to exit, after the response is out.
 *
 * WorkerRestartSignal covers every long-running process except the one serving
 * this request, and cannot cover that one: its mechanism is a timestamp a
 * worker loop compares against on each iteration, and the web process has no
 * loop of its own to check it in. Two things make the gap matter rather than
 * being cosmetic:
 *
 *   - frankenphp/docker-entrypoint.sh EXPORTS the generated secrets as real
 *     environment variables, and config/bootstrap_generated_secrets.php gives a
 *     real environment variable precedence over the file. A rotated secret is
 *     therefore invisible to an already-running process no matter how often it
 *     re-reads the file.
 *   - Dockerfile sets FRANKENPHP_CONFIG="import worker.Caddyfile", so the
 *     kernel boots once and is reused. There is no request boundary at which
 *     anything could be re-read even if the environment did not win.
 *
 * So the only mechanism is a new process, and the only way to get one from in
 * here is to end this one: FrankenPHP is PID 1 and compose gives the `php`
 * service `restart: unless-stopped`, so exiting IS restarting. Same philosophy
 * as WorkerRestartSignal, and for the same reason — the app has no Docker
 * socket and giving it one to solve this would be wildly disproportionate.
 *
 * The signal is sent from kernel.terminate rather than from the controller. In
 * FrankenPHP worker mode Symfony's FrankenPhpWorkerRunner calls terminate()
 * after frankenphp_handle_request() has returned, which is after the response
 * has gone to the client; in the classic SAPI HttpKernelRunner flushes and
 * calls fastcgi_finish_request() first. Either way the page is delivered before
 * the process is asked to go away. Killing from the controller would hand the
 * browser a dead socket instead of the confirmation it is waiting for.
 *
 * PID 1 is only ever signalled when this process IS PID 1, and only from a web
 * SAPI. Being PID 1 alone is not enough, which is not a theoretical objection:
 * `docker compose run --entrypoint docker-php-entrypoint app php …` execs PHP
 * directly, so a console command — the whole test suite included — is PID 1 of
 * its own container and would have passed a check that only asked about the
 * pid. What saved it there was Linux refusing to deliver a default-disposition
 * signal to PID 1, which is luck rather than a design. A command that wants to
 * end itself can simply return; only a request handler has no other way out.
 */
final class WebProcessRestart implements EventSubscriberInterface
{
    /**
     * Spelled out rather than using the SIGTERM constant, which comes from
     * ext-pcntl. The web SAPI has no other reason to load pcntl, and a restart
     * button that silently degrades to "unsupported" because an extension is
     * missing would be a hard failure to diagnose.
     */
    private const int SIGTERM = 15;

    /**
     * Denied rather than allowing the SAPIs known to serve HTTP: a new server
     * runtime should default to "not supported and say so", never to "signal
     * PID 1 and hope".
     */
    private const array CONSOLE_SAPIS = ['cli', 'phpdbg', 'embed'];

    private bool $requested = false;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Last: anything else listening on terminate (the JMAP push drain, for
        // one) should have finished before the process is asked to exit.
        return [TerminateEvent::class => ['onTerminate', -1024]];
    }

    /**
     * Whether exiting this process would actually restart the container.
     *
     * Callers must ask before promising a restart in a page they render —
     * see the honest-degradation note on request().
     */
    public function isSupported(): bool
    {
        return false === in_array(PHP_SAPI, self::CONSOLE_SAPIS, true)
            && function_exists('posix_kill')
            && function_exists('posix_getpid')
            && 1 === posix_getpid();
    }

    /**
     * Schedule the restart for the end of this request.
     *
     * Returns false when it cannot be done, so the caller can say "restart it
     * yourself" rather than claiming a restart that never happens. A page that
     * lies about this is worse than no button: the operator walks away believing
     * the fleet is on the new encryption key.
     */
    public function request(): bool
    {
        if (false === $this->isSupported()) {
            $this->logger->warning('A web container restart was requested but this process cannot end itself; it must be restarted by hand.', [
                'sapi' => PHP_SAPI,
            ]);

            return false;
        }

        $this->requested = true;

        return true;
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (false === $this->requested) {
            return;
        }

        // Cleared first. In worker mode this service outlives the request, and
        // a flag left standing would kill the process on the next request that
        // happened to reach terminate.
        $this->requested = false;

        if (false === posix_kill(1, self::SIGTERM)) {
            $this->logger->error('Could not signal PID 1; the web container has to be restarted by hand.');
        }
    }
}
