<?php

declare(strict_types=1);

namespace App\Service\System\Work;

use App\Domain\DTO\System\BackgroundProcess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Everything plMail runs besides the web server, as the worker container runs
 * it (app:work).
 *
 * ── Processes, not containers ───────────────────────────────────────────────
 * Each queue still gets a process of its own, for the reason it always did: a
 * consumer already inside a long handler cannot pick up anything else, however
 * the queues are prioritised, so a send would wait behind a sync. What changed
 * is only where the processes live. They used to be seven containers of one
 * image; now they are seven children of one supervisor.
 *
 * The names are the containers' old ones, deliberately. Each child gets its
 * name as APP_CONTAINER_NAME, which is what the heartbeats are keyed by and
 * what the admin log viewer shows, so the admin dashboard, /healthz and the log
 * filters read exactly as before.
 *
 * ── The hub ─────────────────────────────────────────────────────────────────
 * The Mercure hub is the one child that is not PHP: the 1.x hub binary the
 * image copies in (see the Dockerfile's mercure_upstream). It is configured
 * here the way the separate hub container used to be configured in compose:
 * plain HTTP on :80, the generated JWT secret as both keys, and the issuer and
 * audience pinned to `mercure.identifier` (config/services.yaml says why they
 * cannot be derived from the install's own address). The container answers to
 * `mercure` on the network, so the web container's proxy and MERCURE_URL reach
 * it where they always reached the hub.
 */
final readonly class BackgroundProcesses
{
    public const string HUB = 'mercure';

    /** The consumers' recycling, as compose ran them: hourly, or at 256 MB. */
    private const array CONSUMER_LIMITS = ['--time-limit=3600', '--memory-limit=256M'];

    public function __construct(
        #[Autowire('%mercure.identifier%')]
        private string  $hubIdentifier,
        // The subscriber cookie's name, as the application sets it. The hub has
        // to look for the same one: `__Secure-…` by default, and whatever
        // MERCURE_COOKIE_NAME says on an install serving plain HTTP, where a
        // browser drops a `__Secure-` cookie. One setting now, where the hub
        // container needed its own copy of it.
        #[Autowire('%mercure.cookie_name%')]
        private string  $cookieName = '__Secure-mercure_access_token',
        #[Autowire('%env(default::MERCURE_JWT_SECRET)%')]
        private ?string $hubSecret = null,
        #[Autowire('%env(default::MERCURE_TRUSTED_ISSUERS)%')]
        private ?string $trustedIssuers = null,
        #[Autowire('%env(default::MERCURE_EXTRA_DIRECTIVES)%')]
        private ?string $extraDirectives = null,
    ) {
    }

    /** @return list<BackgroundProcess> in start order: the hub first, so publishing has somewhere to go */
    public function all(): array
    {
        return [
            $this->hub(),
            new BackgroundProcess('imap-supervisor', ['php', 'bin/console', 'app:imap:supervise']),
            $this->consumer('worker-export', 'export'),
            $this->consumer('worker-ingest', 'ingest'),
            // Also drains `async`, the pre-split queue; see messenger.yaml.
            $this->consumer('worker-maintenance', 'maintenance', 'async'),
            $this->consumer('worker-bulk', 'bulk'),
            $this->consumer('scheduler', 'scheduler_default'),
        ];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (BackgroundProcess $process): string => $process->name, $this->all());
    }

    private function consumer(string $name, string ...$transports): BackgroundProcess
    {
        return new BackgroundProcess($name, ['php', 'bin/console', 'messenger:consume', ...$transports, ...self::CONSUMER_LIMITS]);
    }

    private function hub(): BackgroundProcess
    {
        $secret = (string) $this->hubSecret;

        return new BackgroundProcess(self::HUB, ['mercure', 'run', '--config', '/etc/mercure/Caddyfile', '--adapter', 'caddyfile'], [
            // Plain HTTP inside the stack; TLS, where there is any, is the web
            // container's or the reverse proxy's. Set here rather than
            // inherited: SERVER_NAME in this image is the WEB server's, and a
            // hub that picked up a real hostname would go asking for a
            // certificate.
            'SERVER_NAME'                   => ':80',
            'MERCURE_PUBLISHER_JWT_KEY'     => $secret,
            'MERCURE_SUBSCRIBER_JWT_KEY'    => $secret,
            'MERCURE_TRUSTED_ISSUERS'       => $this->orDefault($this->trustedIssuers, $this->hubIdentifier),
            // The audience pinned rather than derived from each request: plMail
            // reaches the hub by two roads (the browser at the public URL, the
            // application at the internal one), and a hub working the audience
            // out per request would refuse every publish.
            'MERCURE_EXTRA_DIRECTIVES'      => $this->orDefault(
                $this->extraDirectives,
                sprintf("resource_identifier %s\ncookie_name %s", $this->hubIdentifier, $this->cookieName),
            ),
            // Five seconds to close its connections when told to stop, then it
            // closes them. Caddy's own default is to wait for every one, and a
            // live-update subscription is a connection that never ends by
            // itself: the worker took the whole 25-second grace period to stop,
            // every time, and then killed the hub anyway. Browsers reconnect.
            'GLOBAL_OPTIONS'                => 'grace_period 5s',
            // The hub's Caddyfile shares these names with FrankenPHP's. Emptied
            // so nothing meant for the web server configures the hub.
            'CADDY_EXTRA_CONFIG'            => '',
            'CADDY_SERVER_EXTRA_DIRECTIVES' => '',
        ]);
    }

    private function orDefault(?string $value, string $default): string
    {
        $value = trim((string) $value);

        return '' === $value ? $default : $value;
    }
}
