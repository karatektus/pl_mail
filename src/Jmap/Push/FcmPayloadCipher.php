<?php

declare(strict_types=1);

namespace App\Jmap\Push;

use App\Entity\User\PushSubscription;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Encryption;

/**
 * Seals a push payload so that Firebase carries it without being able to read
 * it.
 *
 * Web Push has had this from the start: a browser hands over a public key with
 * its subscription and every payload is encrypted to it (RFC 8291), so the
 * push service relays ciphertext. FCM had no equivalent here. A data message's
 * `payload` is JSON that Google can read, and for a state change that was a
 * decision — it carries tokens and nothing else — but a calendar reminder
 * carries the event's title, and that went to Google in the clear while the
 * code above it said "encrypted end to end" (issue #34).
 *
 * The same scheme, pointed at a different carrier. The app generates the same
 * kind of key pair a browser does and sends the public half with its
 * subscription; this class produces the same `aes128gcm` body a Web Push POST
 * would have had, base64url-encoded so it fits an FCM data field, which holds
 * strings. The app decrypts it on the device with the private half, which
 * never leaves it.
 *
 * Reusing RFC 8291 rather than inventing something for FCM: the encryption is
 * already implemented and tested on this side (minishlink/web-push), there is
 * a published test vector for the other side to check itself against, and "the
 * body a browser would have received" is a sentence a client author can act
 * on without reading this file.
 */
final readonly class FcmPayloadCipher
{
    public function canSeal(PushSubscription $subscription): bool
    {
        return null !== $subscription->p256dh && '' !== $subscription->p256dh
            && null !== $subscription->auth && '' !== $subscription->auth;
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return string base64url of the RFC 8188 `aes128gcm` body: header
     *                (salt, record size, sender's public key) then ciphertext
     */
    public function seal(PushSubscription $subscription, array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        // No padding beyond the delimiter the encoding requires. Padding hides
        // a payload's length from the push service; these are a title and a
        // time, and FCM caps a message at 4 KB.
        $sealed = Encryption::encrypt(
            Encryption::padPayload($json, 0, ContentEncoding::aes128gcm),
            (string) $subscription->p256dh,
            (string) $subscription->auth,
            ContentEncoding::aes128gcm,
        );

        $body = Encryption::getContentCodingHeader($sealed['salt'], $sealed['localPublicKey'], ContentEncoding::aes128gcm)
            . $sealed['cipherText'];

        return rtrim(strtr(base64_encode($body), '+/', '-_'), '=');
    }
}
