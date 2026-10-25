<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Mail\Message;
use App\Repository\Crypto\AddressKeyRepository;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Crypto\ContactKeyCollector;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `attached_sender_key(message)`: the public key the sender attached to their
 * own message, for the crypto banner to say — "The sender's key is attached",
 * with its fingerprint — instead of the attachment list showing a file
 * nobody wants to open (phase 6g of #28). Null when no part is the sender's
 * key (MessagePart::$senderKeyFingerprint).
 *
 * A message that arrived before phase 6g was never looked at that way, so the
 * first time one with a key-looking attachment is shown it is looked at here
 * (ContactKeyCollector::markAttachedKeys()) — once: every part looked at is
 * marked, and the next render reads the stored answer.
 *
 * `state` says where the key stands with plMail, for the banner to offer
 * what fits: `own` (the person's own key, on their own Sent mail), `current`
 * (the key plMail uses for the sender), `changed` (kept beside a different
 * known key, waiting for the person), or `none` (not held — revoked,
 * expired, or not collected yet).
 */
final class AttachedKeyExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContactKeyCollector  $collector,
        private readonly ContactKeyRepository $contactKeys,
        private readonly AddressKeyRepository $addressKeys,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('attached_sender_key', $this->attachedSenderKey(...)),
        ];
    }

    /**
     * @return array{fingerprint: string, state: string, pinned: bool, id: ?int}|null
     */
    public function attachedSenderKey(Message $message): ?array
    {
        if (null === $message->id || null === $message->account->usr) {
            return null;
        }

        if (true === ContactKeyCollector::hasUncheckedKeyParts($message)) {
            $this->collector->markAttachedKeys($message);
        }

        $fingerprint = null;

        foreach ($message->messageParts as $part) {
            $fingerprint ??= $part->senderKeyFingerprint;
        }

        if (null === $fingerprint) {
            return null;
        }

        $user = $message->account->usr;
        $from = strtolower(trim((string) $message->fromAddress));

        foreach ($this->addressKeys->findForUser($user) as $own) {
            if ($fingerprint === $own->fingerprint) {
                return ['fingerprint' => $fingerprint, 'state' => 'own', 'pinned' => true, 'id' => null];
            }
        }

        foreach ($this->contactKeys->findForAddress($user, $from) as $key) {
            if ($fingerprint === $key->fingerprint) {
                return ['fingerprint' => $fingerprint, 'state' => true === $key->isCurrent() ? 'current' : 'changed', 'pinned' => $key->pinned, 'id' => $key->id];
            }
        }

        return ['fingerprint' => $fingerprint, 'state' => 'none', 'pinned' => false, 'id' => null];
    }
}
