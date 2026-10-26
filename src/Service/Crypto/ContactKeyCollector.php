<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOutcome;
use App\Domain\Helper\MessageParser;
use App\Entity\Crypto\ContactKey;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
use App\Repository\Crypto\AddressKeyRepository;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Mail\RawMessageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Webklex\PHPIMAP\Config;

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
 *
 * It also marks which attached key is the sender's own (markAttachedKeys(),
 * phase 6g): that part is then said in the crypto banner rather than listed
 * as an attachment — for the person's own Sent mail too, where "Attach your
 * public key" put their key. Somebody else's key stays an attachment.
 */
final readonly class ContactKeyCollector
{
    public function __construct(
        private Keyring                $keyring,
        private PublicKeyReader        $reader,
        private ContactKeys            $contactKeys,
        private RawMessageResolver     $raw,
        private LoggerInterface        $logger,
        private ContactKeyRepository   $contactKeyRows,
        private AddressKeyRepository   $addressKeys,
        private EntityManagerInterface $entityManager,
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
     * Whether $message has a key-looking attachment markAttachedKeys() has
     * not looked at yet — cheap, from the stored parts alone.
     */
    public static function hasUncheckedKeyParts(Message $message): bool
    {
        foreach ($message->messageParts as $part) {
            if (false === $part->keyChecked && true !== $part->isInline && true === self::looksLikeKey($part->contentType, $part->filename)) {
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

        if (false === filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return [];
        }

        if (true === in_array($from, $this->keyring->ownedAddresses($user), true)) {
            $this->markAttachedKeys($message);

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

        // After recording, so a key just kept counts as one plMail holds.
        $this->markAttachedKeys($message);

        return $outcomes;
    }

    /**
     * Marks every attached part that holds the sender's own public key
     * (MessagePart::$senderKeyFingerprint), and takes it out of the
     * message's hasAttachments — and the thread's count — when it was the
     * only attachment. The sender's key is one with a user ID for the From
     * address, or one plMail already holds for it: a contact key for that
     * address, or for the person's own mail one of their own keys. A key
     * that is neither — a colleague's, forwarded — stays an attachment.
     *
     * Every part looked at is marked as looked at, so this is done once per
     * message: at ingest, through collect(), or the first time a message that
     * arrived earlier is shown (AttachedKeyExtension).
     */
    public function markAttachedKeys(Message $message): void
    {
        $parts = array_values(array_filter(
            $message->messageParts->toArray(),
            static fn (MessagePart $part): bool => false === $part->keyChecked && true !== $part->isInline && true === self::looksLikeKey($part->contentType, $part->filename),
        ));

        if ([] === $parts) {
            return;
        }

        $user = $message->account->usr;
        $from = strtolower(trim((string) $message->fromAddress));
        $held = array_map(static fn (ContactKey $key): string => $key->fingerprint, $this->contactKeyRows->findForAddress($user, $from));

        foreach ($this->addressKeys->findForUser($user) as $own) {
            if (true === in_array($from, $own->addresses(), true)) {
                $held[] = $own->fingerprint;
            }
        }

        $contents = $this->attachedContents($message);

        foreach ($parts as $part) {
            $part->keyChecked = true;

            foreach ($this->reader->read($contents[self::partKey($part->contentType, $part->filename)] ?? '') as $key) {
                if (true === in_array($from, $key->addresses, true) || true === in_array($key->fingerprint, $held, true)) {
                    $part->senderKeyFingerprint = $key->fingerprint;
                    break;
                }
            }
        }

        $this->recount($message);
        $this->entityManager->flush();
    }

    /**
     * hasAttachments without the sender's key, and the thread's count with it.
     * Recounted rather than decremented, as MessageEraser does: a counter
     * nudged by one drifts.
     */
    private function recount(Message $message): void
    {
        $listed = false;

        foreach ($message->messageParts as $part) {
            $listed = $listed || true === $part->isListed();
        }

        if (true !== $message->hasAttachments || true === $listed) {
            return;
        }

        $message->hasAttachments = false;
        $thread                  = $message->thread;

        if (null !== $thread) {
            $withAttachments = 0;

            foreach ($thread->messages as $sibling) {
                if (true === $sibling->hasAttachments) {
                    ++$withAttachments;
                }
            }

            $thread->attachmentCount = $withAttachments;
        }
    }

    /**
     * @return list<string>
     */
    private function attachedKeys(Message $message): array
    {
        return array_values($this->attachedContents($message));
    }

    /**
     * The key-looking attachments of the raw source, by content type and file
     * name (partKey()) — the stored parts are matched to them that way, since
     * the parts plMail keeps carry no other link to the source.
     *
     * @return array<string, string>
     */
    private function attachedContents(Message $message): array
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
            $parsed = MessageParser::fromString(
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
            $type    = (string) $attachment->getContentType();

            if (true === self::looksLikeKey($type, (string) $attachment->getName())
                && strlen($content) <= PublicKeyReader::MAX_BYTES
                && (str_contains($content, '-----BEGIN PGP PUBLIC KEY BLOCK-----') || 'application/pgp-keys' === strtolower(trim(explode(';', $type)[0])))) {
                $keys[self::partKey($type, (string) $attachment->getName())] = $content;
            }
        }

        return $keys;
    }

    private static function partKey(?string $contentType, ?string $filename): string
    {
        return strtolower(trim(explode(';', (string) $contentType)[0])) . '|' . strtolower((string) $filename);
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
