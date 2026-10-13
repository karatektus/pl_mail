<?php

declare(strict_types=1);

namespace App\Service\Calendar\Subscription;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The install's own Nextcloud, when the administrator has named one.
 *
 * A shortcut and nothing more. With NEXTCLOUD_URL set, Settings → Calendars
 * offers "Connect my Nextcloud", which opens the ordinary CalDAV connect form
 * with the server address already decided — the person types a username and
 * an app password, and from there it is CalDavConnector, CalDavDiscovery and
 * CalendarDiscoverer exactly as for an address they typed themselves. There is
 * no Nextcloud provider, no Nextcloud table and no Nextcloud code path: the
 * connection it makes is a CalDAV Integration indistinguishable from a manual
 * one, and the manual form stays beside it for anybody whose calendars are on
 * a different server.
 *
 * Read from the environment, like the other install-wide addresses, because
 * it is a fact about the deployment rather than a preference: the person who
 * knows where the household's Nextcloud is is whoever wrote the compose file.
 *
 * Its host is also exempt from the private-network refusal — see
 * IntegrationUrlValidator::isTrusted(). An administrator naming
 * `https://nextcloud.lan` here has said the same thing as pinning it, and a
 * shortcut that then refused its own address would be worse than none.
 */
final readonly class NextcloudPreset
{
    /** What the connection is called unless the person renames it. */
    public const string NAME = 'Nextcloud';

    public ?string $url;

    public function __construct(
        #[Autowire(env: 'default::NEXTCLOUD_URL')]
        ?string $url = null,
    ) {
        $trimmed   = null === $url ? '' : rtrim(trim($url), '/');
        $this->url = '' === $trimmed ? null : $trimmed;
    }

    public function isConfigured(): bool
    {
        return null !== $this->url;
    }

    /**
     * The host the administrator vouched for, lower-cased, or null.
     */
    public function host(): ?string
    {
        $host = null === $this->url ? null : parse_url($this->url, PHP_URL_HOST);

        return true === is_string($host) && '' !== $host ? strtolower(trim($host, '[]')) : null;
    }
}
