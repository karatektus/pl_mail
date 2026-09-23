<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\ContactRepository;
use App\Repository\Mail\TrustedImageSenderRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Two per-sender questions a conversation asks once per message, answered once
 * per conversation.
 *
 * Opening a thread renders every message, and each asked on its own whether
 * its sender is on the image allowlist (MessageRenderer) and whether the reader
 * has ever written to them (the category report). Two point queries per
 * message; a ten-message thread spent twenty queries learning the same few
 * answers. prime() asks both for every sender of the thread in two IN() reads.
 *
 * ONLY PRIMED ANSWERS ARE REMEMBERED. An address nobody primed goes straight to
 * the repository every time, exactly as before. That keeps a write path honest:
 * a request that trusts a sender and then re-renders the message is not a
 * thread view, never primed, and so cannot be answered from before its own
 * write.
 *
 * Resettable because FrankenPHP keeps services alive between requests, and one
 * reader's allowlist must never answer for the next.
 */
final class ThreadSenderFacts implements ResetInterface
{
    /** @var array<string, array<string, bool>> user id => normalised address => trusted */
    private array $trusted = [];

    /** @var array<string, array<string, bool>> user id => lowercased email => correspondent */
    private array $correspondents = [];

    public function __construct(
        private readonly TrustedImageSenderRepository $trustedSenders,
        private readonly ContactRepository $contacts,
    ) {
    }

    public function reset(): void
    {
        $this->trusted        = [];
        $this->correspondents = [];
    }

    /**
     * @param iterable<Message> $messages the conversation about to be rendered
     */
    public function prime(User $user, iterable $messages): void
    {
        $key       = $user->getUserIdentifier();
        $addresses = [];
        $emails    = [];

        foreach ($messages as $message) {
            $address = AddressHelper::email($message->fromAddress);

            if ('' !== $address) {
                $addresses[$address] = true;
            }

            $email = mb_strtolower(trim((string) $message->fromAddress));

            if ('' !== $email) {
                $emails[$email] = true;
            }
        }

        $trusted = $this->trustedSenders->findTrustedAmong($user, array_keys($addresses));

        foreach (array_keys($addresses) as $address) {
            $this->trusted[$key][$address] = isset($trusted[$address]);
        }

        $correspondents = $this->contacts->findCorrespondentsAmong($user, array_keys($emails));

        foreach (array_keys($emails) as $email) {
            $this->correspondents[$key][$email] = isset($correspondents[$email]);
        }
    }

    /** TrustedImageSenderRepository::isTrusted(), from the primed set when there is one. */
    public function isTrusted(User $user, ?string $address): bool
    {
        return $this->trusted[$user->getUserIdentifier()][AddressHelper::email($address)]
            ?? $this->trustedSenders->isTrusted($user, $address);
    }

    /** ContactRepository::isCorrespondent(), from the primed set when there is one. */
    public function isCorrespondent(User $user, string $email): bool
    {
        return $this->correspondents[$user->getUserIdentifier()][mb_strtolower(trim($email))]
            ?? $this->contacts->isCorrespondent($user, $email);
    }
}
