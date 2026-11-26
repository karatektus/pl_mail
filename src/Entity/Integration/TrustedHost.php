<?php

declare(strict_types=1);

namespace App\Entity\Integration;

use App\Domain\Trait\TimestampableTrait;
use App\Repository\Integration\TrustedHost\TrustedHostRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One server an administrator has said plMail may reach on a private network.
 *
 * A server address a user types — a CalDAV or CardDAV server, a calendar feed,
 * a Nextcloud, a JMAP host — is refused when it resolves into loopback,
 * link-local, private or reserved space (see IntegrationUrlValidator). A row
 * here is the exemption for one host: a Baïkal at `dav.home.example.com` that
 * the LAN's DNS answers with 192.168.1.20, a NAS at `10.0.0.5`.
 *
 * **One row per host, not a list in one column.** This replaced the
 * INTEGRATIONS_ALLOWED_HOSTS environment variable (#564), which a Docker
 * Compose install could not even set — compose.yaml never passed it through —
 * and which needed a container restart to change. A row per host is what lets
 * the admin page add and remove one without retyping the others, and gives the
 * plain-http permission somewhere to live per host.
 *
 * **Plain http is per host** ($allowHttp) rather than the install-wide
 * INTEGRATIONS_ALLOW_HTTP switch it replaced. That switch let every user send
 * an app password in the clear to any server at all, public ones included,
 * because one LAN box had no certificate. Here it is a statement about one
 * server the administrator knows.
 *
 * The host is stored as the URL carries it, lowercased and without IPv6
 * brackets — TrustedHostList::normalise() is the one place that spells that,
 * and the lookups compare against the same form.
 */
#[ORM\Entity(repositoryClass: TrustedHostRepository::class)]
#[ORM\Table(name: 'integration_trusted_host')]
#[ORM\UniqueConstraint(name: 'uniq_integration_trusted_host_host', columns: ['host'])]
#[ORM\HasLifecycleCallbacks]
class TrustedHost
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /** 253 is the longest a DNS name can be; an IPv6 literal is shorter. */
    #[ORM\Column(length: 253)]
    public private(set) string $host;

    #[ORM\Column]
    public bool $allowHttp = false;

    public function __construct(string $host, bool $allowHttp = false)
    {
        $this->host      = $host;
        $this->allowHttp = $allowHttp;
    }
}
