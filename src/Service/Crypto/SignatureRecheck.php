<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\VerifySignatureMessage;
use App\Repository\Mail\MessageRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Checks a sender's signed mail again after what plMail knows about their key
 * changed.
 *
 * A stored signature state is a verdict reached with the keys known at the
 * time. Once a key for the sender turns up, is pinned, unpinned or replaced,
 * those verdicts are out of date in both directions — an UnknownKey that can
 * now be checked, a Valid that is only Untrusted after unpinning — so every
 * state is checked again, not only UnknownKey.
 *
 * Queued, one job per message, on the queue every signature check uses:
 * checking can mean fetching a raw source from Gmail or Microsoft first. The
 * newest messages only, because a contact who signs everything may have years
 * of it, and the old mail matters least.
 */
final readonly class SignatureRecheck
{
    private const int LIMIT = 200;

    public function __construct(
        private MessageRepository   $messages,
        private MessageBusInterface $bus,
    ) {
    }

    public function forSender(User $user, string $email): void
    {
        foreach ($this->messages->findPgpSignedIdsFrom($user, $email, self::LIMIT) as $id) {
            $this->bus->dispatch(new VerifySignatureMessage($id));
        }
    }
}
