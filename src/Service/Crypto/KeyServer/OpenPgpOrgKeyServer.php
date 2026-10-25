<?php

declare(strict_types=1);

namespace App\Service\Crypto\KeyServer;

use App\Domain\DTO\Crypto\KeyServerUpload;
use App\Domain\Enum\Crypto\KeyPublicationState;
use App\Domain\Interface\KeyPublisherInterface;
use App\Domain\Interface\KeyServerInterface;

/**
 * keys.openpgp.org, through its Verifying Key Server API (VKS):
 *
 *     GET https://keys.openpgp.org/vks/v1/by-email/<address, URL-encoded>
 *
 * answers the armored key, or 404.
 *
 * The server **verifies addresses**: a user ID is only handed out once its
 * owner has clicked the link the server mailed to it, and a key whose
 * addresses nobody confirmed comes back without user IDs at all. Such a key
 * has no user ID for the address asked about, so PublicKeyInfo::usableFor()
 * refuses it in KeyServerLookup — which is the right answer: an unconfirmed
 * upload is exactly somebody else's claim to that address.
 *
 * Publishing the person's own key (phase 6g, KeyPublishing) goes through the
 * same API's other half:
 *
 *     POST /vks/v1/upload          {"keytext": "<armored>"}
 *     POST /vks/v1/request-verify  {"token": "…", "addresses": […], "locale": […]}
 *
 * Both answer `{"key_fpr": "…", "token": "…", "status": {"<address>":
 * "unpublished" | "pending" | "published" | "revoked"}}`, or an `error` with
 * a 4xx. The upload is also how a state is asked again — uploading the same
 * key changes nothing and mails nobody — and how a revocation is published:
 * the server hands out the revoked key from then on.
 */
final readonly class OpenPgpOrgKeyServer implements KeyServerInterface, KeyPublisherInterface
{
    public function host(): string
    {
        return 'keys.openpgp.org';
    }

    public function url(string $email): string
    {
        return sprintf('https://keys.openpgp.org/vks/v1/by-email/%s', rawurlencode($email));
    }

    public function accept(): string
    {
        return 'application/pgp-keys';
    }

    public function keyIn(string $body): ?string
    {
        return str_contains($body, '-----BEGIN PGP PUBLIC KEY BLOCK-----') ? $body : null;
    }

    public function uploadUrl(): string
    {
        return 'https://keys.openpgp.org/vks/v1/upload';
    }

    public function uploadBody(string $armored): array
    {
        return ['keytext' => $armored];
    }

    public function confirmUrl(): string
    {
        return 'https://keys.openpgp.org/vks/v1/request-verify';
    }

    public function confirmBody(string $token, array $emails, string $locale): array
    {
        // The locale picks the language of the mail the server sends; it
        // takes a list of preferences and falls back to English.
        return ['token' => $token, 'addresses' => $emails, 'locale' => [str_replace('-', '_', $locale)]];
    }

    public function read(int $status, ?string $body): ?KeyServerUpload
    {
        $answer = 200 === $status && null !== $body ? json_decode($body, true) : null;

        if (false === is_array($answer) || false === is_string($answer['key_fpr'] ?? null) || false === is_array($answer['status'] ?? null)) {
            return null;
        }

        $states = [];

        foreach ($answer['status'] as $email => $state) {
            $known = is_string($state) ? KeyPublicationState::tryFrom($state) : null;

            if (is_string($email) && null !== $known) {
                $states[strtolower($email)] = $known;
            }
        }

        return new KeyServerUpload(strtolower($answer['key_fpr']), $states, is_string($answer['token'] ?? null) ? $answer['token'] : null);
    }
}
