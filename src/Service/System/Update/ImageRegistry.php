<?php

declare(strict_types=1);

namespace App\Service\System\Update;

use App\Domain\DTO\System\PublishedBuild;
use App\Domain\Exception\UpdateCheckFailedException;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The newest build published under one image tag, found the way `docker pull`
 * finds it.
 *
 * ── Why the registry and not the repository ─────────────────────────────────
 * The question an update check answers is "what would a pull bring?", and only
 * the registry knows. The branch is ahead of the image for every commit that
 * builds none (a docs change, a changelog), and a tag exists minutes before its
 * image does. Reading the tag's own image answers with something that can
 * actually be installed.
 *
 * ── The protocol ────────────────────────────────────────────────────────────
 * The OCI distribution API, anonymously: a public image needs no account, only
 * a pull token the registry hands to anyone who asks. The manifest is asked for
 * without one first, and the 401's WWW-Authenticate names where the token comes
 * from. That is the flow every registry speaks, so a fork publishing to Docker
 * Hub is read the same way as ghcr.io.
 *
 * Then: the tag's index, the manifest for this machine's architecture, and the
 * image config it points at, whose labels say which commit and which version
 * the image was built from (docker/metadata-action writes them).
 *
 * ── Which image ─────────────────────────────────────────────────────────────
 * APP_IMAGE, stamped at build time next to APP_VERSION and APP_COMMIT, so a fork
 * building its own image checks its own. A checkout that was never built falls
 * back to the upstream image.
 */
final readonly class ImageRegistry
{
    public const string DEFAULT_IMAGE = 'ghcr.io/karatektus/pl_mail';

    /** A tag names an index (or, on an older registry, one manifest). */
    private const string ACCEPT_INDEX = 'application/vnd.oci.image.index.v1+json, '
        . 'application/vnd.docker.distribution.manifest.list.v2+json, '
        . 'application/vnd.oci.image.manifest.v1+json, '
        . 'application/vnd.docker.distribution.manifest.v2+json';

    private const string ACCEPT_MANIFEST = 'application/vnd.oci.image.manifest.v1+json, '
        . 'application/vnd.docker.distribution.manifest.v2+json';

    /** Per request: an update check is never worth holding a worker for. */
    private const array LIMITS = ['timeout' => 10, 'max_duration' => 20];

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(default::APP_IMAGE)%')]
        private ?string             $image = null,
    ) {
    }

    /** The image this installation reads its updates from, e.g. ghcr.io/karatektus/pl_mail. */
    public function image(): string
    {
        $image = trim((string) $this->image);

        return '' === $image ? self::DEFAULT_IMAGE : $image;
    }

    /**
     * @throws UpdateCheckFailedException when the registry cannot be reached,
     *                                    refuses, or answers with no commit
     */
    public function newest(string $tag): PublishedBuild
    {
        [$host, $repository] = $this->split($this->image());

        $base  = sprintf('https://%s/v2/%s', $host, $repository);
        $token = null;

        try {
            $manifest = $this->get(sprintf('%s/manifests/%s', $base, rawurlencode($tag)), self::ACCEPT_INDEX, $token, $repository);

            if (true === isset($manifest['manifests'])) {
                $digest   = $this->forThisMachine($manifest['manifests']);
                $manifest = $this->get(sprintf('%s/manifests/%s', $base, $digest), self::ACCEPT_MANIFEST, $token, $repository);
            }

            $config = $manifest['config']['digest'] ?? null;

            if (false === is_string($config)) {
                throw new UpdateCheckFailedException(sprintf('%s:%s has no image config', $this->image(), $tag));
            }

            // Blobs are served from a CDN behind a redirect. The client follows
            // it and drops the Authorization header on the way, which is what
            // the signed URL expects.
            $image = $this->get(sprintf('%s/blobs/%s', $base, $config), 'application/json', $token, $repository);
        } catch (ExceptionInterface $e) {
            throw new UpdateCheckFailedException(sprintf('%s could not be reached: %s', $host, $e->getMessage()), 0, $e);
        }

        return $this->build($image, $tag);
    }

    /**
     * The image's own account of itself. Labels first; the environment the
     * Dockerfile bakes in as the fallback, for an image built without them.
     *
     * @param array<string, mixed> $image
     */
    private function build(array $image, string $tag): PublishedBuild
    {
        $labels = $image['config']['Labels'] ?? [];
        $labels = is_array($labels) ? $labels : [];
        $env    = $this->environment($image['config']['Env'] ?? []);

        $revision = $labels['org.opencontainers.image.revision'] ?? $env['APP_COMMIT'] ?? '';
        $version  = $labels['org.opencontainers.image.version'] ?? $env['APP_VERSION'] ?? $tag;
        $created  = $labels['org.opencontainers.image.created'] ?? $image['created'] ?? null;
        $source   = $labels['org.opencontainers.image.source'] ?? null;

        if (false === is_string($revision) || 1 !== preg_match('/^[0-9a-f]{7,64}$/', $revision)) {
            throw new UpdateCheckFailedException(sprintf('%s:%s does not say which commit it was built from', $this->image(), $tag));
        }

        $builtAt = null;

        if (is_string($created)) {
            try {
                $builtAt = new DateTimeImmutable($created);
            } catch (\Exception) {
                $builtAt = null;
            }
        }

        return new PublishedBuild(
            is_string($version) && '' !== $version ? $version : $tag,
            $revision,
            $builtAt,
            is_string($source) && '' !== $source ? $source : null,
        );
    }

    /**
     * One GET, with the registry's token dance on the first 401.
     *
     * @return array<string, mixed>
     */
    private function get(string $url, string $accept, ?string &$token, string $repository): array
    {
        $response = $this->request($url, $accept, $token);

        if (401 === $response->getStatusCode() && null === $token) {
            $challenge = $response->getHeaders(false)['www-authenticate'][0] ?? '';
            $token     = $this->token($challenge, $repository);
            $response  = $this->request($url, $accept, $token);
        }

        $status = $response->getStatusCode();

        if (200 !== $status) {
            throw new UpdateCheckFailedException(sprintf(
                404 === $status ? '%s has no such image or tag (%s)' : '%s answered %s',
                parse_url($url, PHP_URL_HOST),
                404 === $status ? parse_url($url, PHP_URL_PATH) : 'HTTP ' . $status,
            ));
        }

        return $response->toArray();
    }

    private function request(string $url, string $accept, ?string $token): ResponseInterface
    {
        $headers = ['Accept' => $accept];

        if (null !== $token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->http->request('GET', $url, ['headers' => $headers] + self::LIMITS);
    }

    /**
     * An anonymous pull token from wherever the registry's challenge says.
     *
     * `Bearer realm="https://ghcr.io/token",service="ghcr.io",scope="repository:x/y:pull"`.
     * The realm has to be https: a token endpoint the challenge could point at
     * plain http is one a network in between could answer.
     */
    private function token(string $challenge, string $repository): string
    {
        preg_match_all('/(\w+)="([^"]*)"/', $challenge, $pairs, PREG_SET_ORDER);

        $params = [];

        foreach ($pairs as $pair) {
            $params[strtolower($pair[1])] = $pair[2];
        }

        $realm = $params['realm'] ?? '';

        if (false === str_starts_with($realm, 'https://')) {
            throw new UpdateCheckFailedException('the registry asked for a login this check cannot give');
        }

        $query = array_filter([
            'service' => $params['service'] ?? null,
            'scope'   => $params['scope'] ?? sprintf('repository:%s:pull', $repository),
        ]);

        $response = $this->http->request('GET', $realm, ['query' => $query] + self::LIMITS);

        if (200 !== $response->getStatusCode()) {
            throw new UpdateCheckFailedException(sprintf('%s refused an anonymous pull (HTTP %d)', parse_url($realm, PHP_URL_HOST), $response->getStatusCode()));
        }

        $body  = $response->toArray();
        $token = $body['token'] ?? $body['access_token'] ?? null;

        if (false === is_string($token) || '' === $token) {
            throw new UpdateCheckFailedException('the registry answered without a pull token');
        }

        return $token;
    }

    /**
     * The manifest for the machine that is asking, since that is the one its
     * pull would bring. A tag built for one architecture only still gets an
     * index; an index entry with no platform, or `unknown/unknown` (an
     * attestation), is not an image.
     *
     * @param mixed $manifests
     */
    private function forThisMachine(mixed $manifests): string
    {
        $wanted = match (php_uname('m')) {
            'aarch64', 'arm64' => 'arm64',
            default            => 'amd64',
        };

        $fallback = null;

        foreach (is_array($manifests) ? $manifests : [] as $entry) {
            $platform = $entry['platform'] ?? [];
            $digest   = $entry['digest'] ?? null;

            if (false === is_string($digest) || 'linux' !== ($platform['os'] ?? null)) {
                continue;
            }

            if ($wanted === ($platform['architecture'] ?? null)) {
                return $digest;
            }

            $fallback ??= $digest;
        }

        return $fallback ?? throw new UpdateCheckFailedException(sprintf('%s publishes no Linux image', $this->image()));
    }

    /**
     * `ghcr.io/owner/name` → [ghcr.io, owner/name]. A reference whose first
     * part is not a host (no dot, no port, not localhost) is Docker Hub's, where
     * a bare name is an official image under library/. A tag or digest on the
     * reference is dropped: the tag asked for is the channel's.
     *
     * @return array{string, string}
     */
    private function split(string $image): array
    {
        $parts = explode('/', $image, 2);
        $host  = $parts[0];

        if (1 === count($parts)) {
            [$host, $repository] = ['registry-1.docker.io', 'library/' . $image];
        } elseif (false === str_contains($host, '.') && false === str_contains($host, ':') && 'localhost' !== $host) {
            [$host, $repository] = ['registry-1.docker.io', $image];
        } else {
            $repository = $parts[1];
        }

        return [$host, preg_replace('/(@.*|:[^\/]*)$/', '', $repository) ?? $repository];
    }

    /**
     * @param mixed $env
     *
     * @return array<string, string>
     */
    private function environment(mixed $env): array
    {
        $values = [];

        foreach (is_array($env) ? $env : [] as $line) {
            if (is_string($line) && str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $values[$name]  = $value;
            }
        }

        return $values;
    }
}
