<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOutcome;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
use App\Service\Mail\RawMessageResolver;
use Psr\Log\LoggerInterface;
use Throwable;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Picks up the keys an incoming message brings for its sender: an Autocrypt
 * header, and public keys attached to it (`application/pgp-keys`, or an
 * `.asc` file holding a key block).
 *
 * Only ever for the sender, and only keys with a user ID for the sender's
 * address. A message can carry anybody's key — a forwarded one, a colleague's
 * — and taking those would let any stranger plant a key for somebody else;
 * the sender vouching for their own key is the one claim the message can
 * make. Never for mail the person sent themselves: their own keys are their
 * keyring's, not a contact's.
 *
 * The header comes from the stored header bag. Attachments come from the raw
 * source, because the parts plMail stores are for showing and downloading and
 * may not have been fetched yet; only messages whose stored parts look like a
 * key are opened at all.
 */
final readonly class ContactKeyCollector
{
    public function __construct(
        private Keyring            $keyring,
        private PublicKeyReader    $reader,
        private ContactKeys        $contactKeys,
        private RawMessageResolver $raw,
        private LoggerInterface    $logger,
    ) {
    }

    /**
     * Whether $message could bring a key at all — cheap enough for the
     * post-ingest step to ask of every message in a batch.
     */
    public static function mayCarryKeys(Message $message): bool
    {
        if (null !== ($message->headers['autocrypt'] ?? null)) {
            return true;
        }

        foreach ($message->messageParts as $part) {
            if (true === self::looksLikeKey($part->contentType, $part->filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ContactKeyOutcome> one per key offered
     */
    public function collect(Message $message): array
    {
        $user = $message->account->usr;
        $from = strtolower(trim((string) $message->fromAddress));

        if (false === filter_var($from, FILTER_VALIDATE_EMAIL) || true === in_array($from, $this->keyring->ownedAddresses($user), true)) {
            return [];
        }

        $offers = [];
        $autocrypt = Autocrypt::keyFor($message->headers ?? [], $from);

        if (null !== $autocrypt) {
            $offers[] = [$autocrypt, ContactKeyOrigin::Autocrypt];
        }

        foreach ($this->attachedKeys($message) as $attached) {
            $offers[] = [$attached, ContactKeyOrigin::Attached];
        }

        $outcomes = [];

        foreach ($offers as [$data, $origin]) {
            foreach ($this->reader->read($data) as $key) {
                $outcomes[] = $this->contactKeys->record($user, $from, $key, $origin);
            }
        }

        return $outcomes;
    }

    /**
     * @return list<string>
     */
    private function attachedKeys(Message $message): array
    {
        $parts = array_filter(
            $message->messageParts->toArray(),
            static fn (MessagePart $part): bool => self::looksLikeKey($part->contentType, $part->filename),
        );

        if ([] === $parts) {
            return [];
        }

        $path = $this->raw->absolutePathFor($message);

        if (null === $path || false === is_file($path)) {
            return [];
        }

        try {
            $parsed = ImapMessage::fromString(
                (string) file_get_contents($path),
                Config::make(['decoding' => ['decoder' => ['message' => Utf8AwareMessageDecoder::class]]]),
            );
        } catch (Throwable $exception) {
            $this->logger->info('Could not parse a message for attached keys: {error}', ['error' => $exception->getMessage(), 'messageId' => $message->id]);

            return [];
        }

        $keys = [];

        foreach ($parsed->getAttachments() as $attachment) {
            $content = (string) $attachment->getContent();

            if (true === self::looksLikeKey((string) $attachment->getContentType(), (string) $attachment->getName())
                && strlen($content) <= PublicKeyReader::MAX_BYTES
                && (str_contains($content, '-----BEGIN PGP PUBLIC KEY BLOCK-----') || 'application/pgp-keys' === strtolower((string) $attachment->getContentType()))) {
                $keys[] = $content;
            }
        }

        return $keys;
    }

    private static function looksLikeKey(?string $contentType, ?string $filename): bool
    {
        $type = strtolower(trim(explode(';', (string) $contentType)[0]));
        $name = strtolower((string) $filename);

        if ('application/pgp-signature' === $type || 'application/pgp-encrypted' === $type || 'signature.asc' === $name || 'encrypted.asc' === $name) {
            return false;
        }

        return 'application/pgp-keys' === $type || str_ends_with($name, '.asc') || str_ends_with($name, '.key') || str_ends_with($name, '.pub');
    }
}
