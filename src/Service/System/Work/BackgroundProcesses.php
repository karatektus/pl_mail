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
 * image; now they are children of one supervisor, and there are ten of them.
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
 * `mercure` on the network in plMail's own compose files, so the web
 * container's proxy and MERCURE_URL reach it where they always reached the
 * hub; a stack that names it otherwise says so in MERCURE_UPSTREAM and
 * MERCURE_URL.
 */
final readonly class BackgroundProcesses
{
    public const string HUB = 'mercure';

    /**
     * The subscriber cookie with and without its prefix. The browser is handed
     * the prefixed one on an https install; the hub only ever sees the bare one.
     */
    private const string PREFIXED_COOKIE = '__Secure-mercure_access_token';
    private const string BARE_COOKIE     = 'mercure_access_token';

    /** The consumers' recycling, as compose ran them: hourly, or at 256 MB. */
    private const array CONSUMER_LIMITS = ['--time-limit=3600', '--memory-limit=256M'];

    public function __construct(
        #[Autowire('%mercure.identifier%')]
        private string  $hubIdentifier,
        // The subscriber cookie's name, as the application sets it. See
        // hubCookieName() for the one name the hub does not take as it comes.
        #[Autowire('%mercure.cookie_name%')]
        private string  $cookieName = self::BARE_COOKIE,
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
            ...$this->consumerProcesses(),
        ];
    }

    /**
     * Which worker consumes which queues, in the order it looks at them.
     *
     * ONE LIST, read twice: all() starts a process from each row, and Admin →
     * Performance reads it to say which worker a queue's wait belongs to. They
     * were two facts that had to agree and nothing made them.
     *
     * The order within a row is the priority — Messenger drains an earlier
     * transport before it looks at a later one:
     *
     *   worker-backlog  a mailbox's history, a page at a time, so that mail
     *                   arriving now is never behind it on worker-ingest
     *   worker-live     mail that has just arrived, and nothing else, so that
     *                   it is never behind an import
     *   worker-release  ends holds, and is otherwise idle on purpose
     *   worker-enrich   `enrich` before `enrich_backlog`
     *   worker-maintenance  also drains `async`, the pre-split queue
     *
     * See messenger.yaml for what each queue is for.
     *
     * @return array<string, list<string>>
     */
    public function consumers(): array
    {
        return [
            'worker-export'      => ['export'],
            'worker-ingest'      => ['ingest'],
            'worker-backlog'     => ['ingest_backlog'],
            'worker-live'        => ['enrich_live'],
            'worker-release'     => ['release'],
            'worker-enrich'      => ['enrich', 'enrich_backlog'],
            'worker-maintenance' => ['maintenance', 'async'],
            'worker-bulk'        => ['bulk'],
            'scheduler'          => ['scheduler_default'],
        ];
    }

    /** @return list<BackgroundProcess> */
    private function consumerProcesses(): array
    {
        $processes = [];

        foreach ($this->consumers() as $name => $transports) {
            $processes[] = $this->consumer($name, ...$transports);
        }

        return $processes;
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
                sprintf("resource_identifier %s\ncookie_name %s", $this->hubIdentifier, $this->hubCookieName()),
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

    /**
     * The cookie the hub looks for.
     *
     * The application's own name, except the prefixed one, which reaches the
     * hub bare: the web server renames it in the proxy (frankenphp/Caddyfile).
     * Whether the browser's cookie carries the prefix follows the public
     * address, which an administrator can change while this process runs — and
     * a hub told the name at ITS start would then refuse every subscription
     * until somebody restarted the worker as well. The hub's side never changes
     * now, so the web container is the only one that has to notice.
     *
     * Any other name is an operator's own and is used as it is, on both sides.
     */
    private function hubCookieName(): string
    {
        return self::PREFIXED_COOKIE === $this->cookieName ? self::BARE_COOKIE : $this->cookieName;
    }

    private function orDefault(?string $value, string $default): string
    {
        $value = trim((string) $value);

        return '' === $value ? $default : $value;
    }
}
