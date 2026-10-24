<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Crypto\KeyServerUpload;

/**
 * A public key server the person can publish their own key to, through its
 * own upload API (App\Service\Crypto\KeyPublishing).
 *
 * The requests themselves are KeyFetcher's, under the rules every request
 * that leaves the server for a key is held to; which servers are offered is
 * APP_KEY_SERVERS's, as for lookups. This says only how this server's API is
 * spoken: where to upload, how to ask it to confirm addresses, and how to
 * read its answer. A server whose API cannot do both — take a key and mail
 * its addresses a confirmation link, and take a revoked key — has no
 * implementation.
 */
interface KeyPublisherInterface
{
    /** As KeyServerInterface::host(), and the same name in APP_KEY_SERVERS. */
    public function host(): string;

    /** The URL a key — or its revoked version — is uploaded to. */
    public function uploadUrl(): string;

    /**
     * @return array<string, mixed> the JSON body of an upload
     */
    public function uploadBody(string $armored): array;

    /** The URL that asks the server to mail the addresses a confirmation link. */
    public function confirmUrl(): string;

    /**
     * @param list<string> $emails
     *
     * @return array<string, mixed> the JSON body asking for those addresses
     */
    public function confirmBody(string $token, array $emails, string $locale): array;

    /** The answer to either request, or null when it is not one. */
    public function read(int $status, ?string $body): ?KeyServerUpload;
}
