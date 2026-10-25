<?php

declare(strict_types=1);

namespace App\Service\Crypto\KeyServer;

use App\Domain\Interface\KeyServerInterface;

/**
 * The Mailvelope key server, through its REST API:
 *
 *     GET https://keys.mailvelope.com/api/v1/key?email=<address, URL-encoded>
 *
 * answers JSON whose `publicKeyArmored` is the key, or 404. Like
 * keys.openpgp.org it only publishes addresses their owner confirmed by mail;
 * whatever it sends, the key still has to carry a user ID for the address
 * (KeyServerLookup).
 */
final readonly class MailvelopeKeyServer implements KeyServerInterface
{
    public function host(): string
    {
        return 'keys.mailvelope.com';
    }

    public function url(string $email): string
    {
        return sprintf('https://keys.mailvelope.com/api/v1/key?email=%s', rawurlencode($email));
    }

    public function accept(): string
    {
        return 'application/json';
    }

    public function keyIn(string $body): ?string
    {
        $answer  = json_decode($body, true);
        $armored = is_array($answer) ? ($answer['publicKeyArmored'] ?? null) : null;

        return is_string($armored) && '' !== $armored ? $armored : null;
    }
}
