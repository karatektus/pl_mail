<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Helper\SealedMessageStorage;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Crypto\ContactKeyRepository;

/**
 * Whether a message the browser built may still go out as it was built.
 *
 * Asked twice: when the browser hands the message over (SealedDrafts), and
 * again when the send job is about to put it on the wire (MessageSendService)
 * — which for a scheduled send can be days later. In between, a recipient's
 * key can be revoked or replaced, and the draft's recipients can be changed by
 * something other than the composer that sealed it (a JMAP client, say). Each
 * of those means the bytes on disk are no longer the message the person
 * meant to send, and none of them can be repaired here: the server holds no
 * key to seal anything with. So the send is refused and the person is told,
 * and sending it again from the composer seals it anew.
 *
 * A sealed draft with no built message is refused outright: there is no body
 * the server could send instead.
 */
final readonly class SealGuard
{
    public const string KIND_ENCRYPTED = 'encrypted';
    public const string KIND_SIGNED    = 'signed';
    public const string KIND_PLAIN     = 'plain';

    public function __construct(
        private SealedMessageStorage $storage,
        private ContactKeyRepository $contactKeys,
        private SendingKey           $sendingKeys,
        private PublicKeyReader      $reader,
    ) {
    }

    /**
     * Why this message must not be sent as it stands, or null when it may.
     *
     * The reason is for the log, not for the person: their notification says
     * the encrypted message has to be sent again from the composer.
     */
    public function refusal(Message $message): ?string
    {
        $seal = $message->outgoingSeal;

        if (null === $seal) {
            return true === $message->sealedDraft ? 'a sealed draft has no message built for it' : null;
        }

        if (null === $message->id || null === $this->storage->read($message->id, SealedMessageStorage::OUTGOING)) {
            return 'the built message is missing';
        }

        if (self::recipientsOf($message) !== ($seal['recipients'] ?? null)) {
            return 'the recipients changed after the message was built';
        }

        if (self::KIND_ENCRYPTED !== ($seal['kind'] ?? null)) {
            return null;
        }

        return $this->keyRefusal($message->account->usr, $seal['recipients'], $seal['fingerprints'] ?? []);
    }

    /**
     * Whether every recipient has a key plMail would encrypt to now, and the
     * message was encrypted to exactly that key.
     *
     * Their own addresses count: a copy to oneself is encrypted to one's own
     * key, which the browser always adds.
     *
     * @param list<string> $recipients
     * @param list<string> $fingerprints
     */
    public function keyRefusal(User $user, array $recipients, array $fingerprints): ?string
    {
        $fingerprints = array_map('strtolower', $fingerprints);

        foreach ($recipients as $email) {
            $own = $this->sendingKeys->for($user, $email);

            if (null !== $own) {
                if (false === in_array(strtolower($own->fingerprint), $fingerprints, true)) {
                    return sprintf('not encrypted to the own key of %s', $email);
                }

                continue;
            }

            $key = $this->contactKeys->findCurrent($user, $email);

            if (null === $key || false === in_array(strtolower($key->fingerprint), $fingerprints, true)) {
                return sprintf('not encrypted to the current key of %s', $email);
            }

            $info = $this->reader->read($key->publicKey)[0] ?? null;

            if (null === $info || false === $info->usableFor($email) || false === $info->canEncrypt) {
                return sprintf('the key of %s can no longer be used', $email);
            }
        }

        return null;
    }

    /**
     * Every address the message goes to, the form the seal records:
     * lower-cased, each once, sorted.
     *
     * @return list<string>
     */
    public static function recipientsOf(Message $message): array
    {
        $addresses = [];

        foreach ([$message->toAddresses, $message->ccAddresses, $message->bccAddresses] as $list) {
            foreach ($list ?? [] as $entry) {
                $address = strtolower(trim((string) ($entry['address'] ?? '')));

                if ('' !== $address) {
                    $addresses[$address] = true;
                }
            }
        }

        $addresses = array_keys($addresses);
        sort($addresses);

        return $addresses;
    }
}
