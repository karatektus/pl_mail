<?php

declare(strict_types=1);

namespace App\Service\Calendar\Push;

use App\Service\Setup\PublicUrlSetting;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The address a calendar provider calls back to, and whether there is one.
 *
 * Built from the configured public base URL rather than from the incoming
 * request, for the reason GraphSubscriptionManager states at length: reverse
 * proxies are the normal deployment, so a URL derived from a request carries an
 * internal hostname, or http:// after TLS termination, and the provider rejects
 * the registration with a validation error that is genuinely unpleasant to
 * diagnose. There is no request at all in the process that actually registers
 * these — a scheduled console command — which settles it.
 *
 * Resolved per call, never injected as a string. The workers are long-running
 * and the public address is typically saved from the setup screen after they
 * booted; a value snapshotted at container build would leave push permanently
 * unconfigured on the very install that just fixed it.
 *
 * This restates GraphSubscriptionManager's routability rule rather than calling
 * into it, and that is a deliberate duplication of about eight lines. That check
 * is private to a class keyed on Account, the two are free to diverge — Graph
 * mail could one day accept something calendar push cannot — and the honest
 * alternative, a shared class both reach for, would mean editing the mail push
 * manager to take it. What must not diverge is the *answer*, so the rule is
 * written here in the same terms: HTTPS, and not a loopback name.
 */
final readonly class PushCallbackUrl
{
    /**
     * Hosts that resolve to the machine plMail runs on, and therefore to
     * nothing at all as far as Google or Microsoft is concerned.
     *
     * @var list<string>
     */
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private PublicUrlSetting      $publicUrl,
    ) {}

    /**
     * Whether a callback could arrive here at all.
     *
     * Both providers refuse a notification URL that is not HTTPS and neither
     * will ever reach a loopback address, so answering this locally turns a
     * confusing remote rejection — repeated once per calendar — into one log
     * line that names the missing setting.
     */
    public function isPubliclyRoutable(): bool
    {
        $base = trim((string) $this->publicUrl->current());

        if ('' === $base) {
            return false;
        }

        if (false === str_starts_with($base, 'https://')) {
            return false;
        }

        $host = parse_url($base, PHP_URL_HOST);

        if (false === is_string($host)) {
            return false;
        }

        return false === in_array($host, self::LOOPBACK_HOSTS, true);
    }

    /** The absolute URL of one webhook route, against the configured base. */
    public function of(string $route): string
    {
        return rtrim((string) $this->publicUrl->current(), '/') . $this->urlGenerator->generate($route);
    }

    /** What the log line and the admin panel show when there is nothing usable. */
    public function base(): string
    {
        return (string) $this->publicUrl->current();
    }
}
