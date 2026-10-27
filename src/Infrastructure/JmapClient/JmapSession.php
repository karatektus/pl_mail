<?php

declare(strict_types=1);

namespace App\Infrastructure\JmapClient;

/**
 * The session resource a JMAP server publishes (RFC 8620 §2), as far as plMail
 * reads it.
 *
 * Everything a request needs to be addressed is in here — the API URL, the
 * account id per capability, the download template — so a caller never parses
 * the session itself. The URLs are resolved against the session URL on the way
 * in: the RFC requires them absolute, and a server that sends a relative one
 * is still a server whose mail somebody wants.
 */
final readonly class JmapSession
{
    /**
     * @param array<string, array<string, mixed>> $capabilities    capability URI → server-level properties
     * @param array<string, string>               $primaryAccounts capability URI → account id
     */
    public function __construct(
        public string  $apiUrl,
        public string  $downloadUrl,
        public string  $uploadUrl,
        public ?string $eventSourceUrl,
        public string  $state,
        public string  $username,
        public array   $capabilities,
        public array   $primaryAccounts,
    ) {
    }

    /**
     * Read a session object, failing loudly on one that cannot be used.
     *
     * @param array<string, mixed> $json the decoded session resource
     *
     * @throws \InvalidArgumentException when the object is not a usable session
     */
    public static function fromArray(array $json, string $sessionUrl): self
    {
        foreach (['apiUrl', 'downloadUrl', 'uploadUrl', 'state'] as $key) {
            if (false === is_string($json[$key] ?? null) || '' === $json[$key]) {
                throw new \InvalidArgumentException(sprintf('JMAP session has no %s.', $key));
            }
        }

        $capabilities = is_array($json['capabilities'] ?? null) ? $json['capabilities'] : [];

        if (false === array_key_exists(JmapCapability::CORE, $capabilities)) {
            throw new \InvalidArgumentException('JMAP session does not declare the core capability.');
        }

        $primary = [];

        foreach (is_array($json['primaryAccounts'] ?? null) ? $json['primaryAccounts'] : [] as $capability => $accountId) {
            if (true === is_string($capability) && true === is_string($accountId)) {
                $primary[$capability] = $accountId;
            }
        }

        // A capability with no primary account is still the account's that
        // declares it. RFC 8620 has the server name one for every capability
        // it has, but a server that leaves submission out — plMail's own did
        // — would otherwise read as having none, though its session says
        // otherwise: the connection test promised sending over JMAP and the
        // send went to SMTP. The mail account first, since that is where
        // submission is sent from; then the first that declares it.
        $declaring = [];

        foreach (is_array($json['accounts'] ?? null) ? $json['accounts'] : [] as $accountId => $account) {
            foreach (array_keys(is_array($account['accountCapabilities'] ?? null) ? $account['accountCapabilities'] : []) as $capability) {
                $declaring[(string) $capability][] = (string) $accountId;
            }
        }

        foreach ($declaring as $capability => $accountIds) {
            if (false === isset($primary[$capability])) {
                $mail                 = $primary[JmapCapability::MAIL] ?? null;
                $primary[$capability] = null !== $mail && in_array($mail, $accountIds, true) ? $mail : $accountIds[0];
            }
        }

        $eventSource = $json['eventSourceUrl'] ?? null;

        return new self(
            apiUrl:          self::absolute((string) $json['apiUrl'], $sessionUrl),
            downloadUrl:     self::absolute((string) $json['downloadUrl'], $sessionUrl),
            uploadUrl:       self::absolute((string) $json['uploadUrl'], $sessionUrl),
            eventSourceUrl:  true === is_string($eventSource) && '' !== $eventSource ? self::absolute($eventSource, $sessionUrl) : null,
            state:           (string) $json['state'],
            username:        is_string($json['username'] ?? null) ? $json['username'] : '',
            capabilities:    array_map(static fn (mixed $props): array => is_array($props) ? $props : [], $capabilities),
            primaryAccounts: $primary,
        );
    }

    public function has(string $capability): bool
    {
        return true === array_key_exists($capability, $this->capabilities);
    }

    /**
     * The account a capability's methods are addressed to.
     *
     * The primary account for that capability, which is what every client
     * uses for "my mail". Null when the server offers the capability to no
     * account of this user — a calendar-less Stalwart account says CALENDARS
     * at the server level and names no primary account for it.
     */
    public function accountFor(string $capability): ?string
    {
        if (false === $this->has($capability)) {
            return null;
        }

        return $this->primaryAccounts[$capability] ?? null;
    }

    /**
     * One of the core limits, with the RFC's own floor as the fallback.
     *
     * The floors are what RFC 8620 §2 says a server must allow at least, so a
     * server that omits the property is not trusted with more than that.
     */
    public function coreLimit(string $name): int
    {
        $floors = [
            'maxCallsInRequest'   => 16,
            'maxObjectsInGet'     => 500,
            'maxObjectsInSet'     => 500,
            'maxSizeRequest'      => 10_000_000,
            'maxSizeUpload'       => 50_000_000,
            'maxConcurrentUpload' => 4,
        ];

        $value = $this->capabilities[JmapCapability::CORE][$name] ?? null;

        return true === is_int($value) && $value > 0 ? $value : ($floors[$name] ?? 1);
    }

    /**
     * The download URL for one blob, from the server's URI template.
     *
     * RFC 6570 level-1 expansion of the four variables the RFC names, and
     * nothing more: that is all the template is allowed to contain.
     */
    public function downloadUrlFor(string $accountId, string $blobId, string $type, string $name): string
    {
        return strtr($this->downloadUrl, [
            '{accountId}' => rawurlencode($accountId),
            '{blobId}'    => rawurlencode($blobId),
            '{type}'      => rawurlencode($type),
            '{name}'      => rawurlencode($name),
        ]);
    }

    public function uploadUrlFor(string $accountId): string
    {
        return strtr($this->uploadUrl, ['{accountId}' => rawurlencode($accountId)]);
    }

    /**
     * Resolve a URL from the session against the session URL itself.
     *
     * Only the two relative forms a server plausibly sends — an absolute path
     * and a bare path — because anything else is a server that does not mean
     * to be reached and will fail on the request regardless.
     */
    private static function absolute(string $url, string $base): string
    {
        if (1 === preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $parts  = parse_url($base);
        $origin = sprintf(
            '%s://%s%s',
            $parts['scheme'] ?? 'https',
            $parts['host'] ?? '',
            isset($parts['port']) ? ':' . $parts['port'] : '',
        );

        if (true === str_starts_with($url, '/')) {
            return $origin . $url;
        }

        $path = $parts['path'] ?? '/';

        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $url;
    }
}
