<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Integration\TrustedHost;
use App\Repository\Integration\TrustedHost\TrustedHostRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The servers an administrator lets plMail reach on a private network, as
 * Admin → Integrations edits them.
 *
 * A service rather than three lines in the controller for one reason: the
 * spelling of a host. The validator looks a host up by the form a URL carries
 * it in, so what the admin types has to be brought to that same form before it
 * is stored, or `Dav.Example.com` is saved and `dav.example.com` stays
 * refused. normalise() is that one spelling, and IntegrationUrlValidator asks
 * it too.
 */
final readonly class TrustedHostList
{
    public function __construct(
        private TrustedHostRepository  $hosts,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The host as plMail compares it, or null when the input names no host.
     *
     * Accepts a whole address as well as a bare host, because the address is
     * what an administrator has to hand — it is in the error the user just
     * reported — and making them cut the host out of it by hand is how a path
     * or a port ends up in the list and never matches. Lowercased, IPv6
     * brackets dropped, a trailing dot dropped (`dav.lan.` and `dav.lan` are
     * one name to the resolver).
     *
     * No wildcards, deliberately. `*.lan` would let every user reach every
     * machine on the network by name, which is the thing the rule exists to
     * stop; an administrator who means three servers lists three.
     */
    public static function normalise(string $input): ?string
    {
        $input = trim($input);

        if (1 === preg_match('~^[a-z][a-z0-9+.-]*://~i', $input)) {
            $input = (string) parse_url($input, PHP_URL_HOST);
        }

        $host = rtrim(strtolower(trim($input, '[]')), '.');

        if ('' === $host) {
            return null;
        }

        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        // A dotted quad that is not a valid address (`300.1.1.1`) passes the
        // hostname check below; nothing resolves it, so it would sit in the
        // list matching nothing.
        if (1 === preg_match('/^[0-9.]+$/', $host)) {
            return null;
        }

        return false !== filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? $host : null;
    }

    /**
     * @return list<TrustedHost>
     */
    public function all(): array
    {
        return $this->hosts->findAllOrdered();
    }

    /**
     * Allow a host, or change whether it may use plain http when it is already
     * allowed — adding a host twice is the admin correcting the checkbox, not
     * an error.
     *
     * @param string $host already through normalise(); the form checks that
     */
    public function allow(string $host, bool $allowHttp): TrustedHost
    {
        $trusted = $this->hosts->findOneByHost($host);

        if (null === $trusted) {
            $trusted = new TrustedHost($host, $allowHttp);
            $this->entityManager->persist($trusted);
        }

        $trusted->allowHttp = $allowHttp;

        $this->entityManager->flush();

        return $trusted;
    }

    public function setAllowHttp(TrustedHost $host, bool $allowHttp): void
    {
        $host->allowHttp = $allowHttp;

        $this->entityManager->flush();
    }

    /**
     * Stop allowing a host. Connections already made to it stop syncing on
     * their next request with "must not point at a private network" — the
     * validator and the HTTP client both ask on every request — which is the
     * point of removing it.
     */
    public function remove(TrustedHost $host): void
    {
        $this->entityManager->remove($host);
        $this->entityManager->flush();
    }
}
