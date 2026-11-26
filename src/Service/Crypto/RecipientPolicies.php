<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Domain\Exception\ContactKeyRefused;
use App\Entity\User\User;
use App\Repository\Crypto\ContactKey\ContactKeyRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whether mail to one address is encrypted from now on (#703): what the lock's
 * popover in the composer sets, for the web app and, through
 * RecipientPolicy/set, for a native one.
 *
 * Two places, because there are two kinds of address. A contact's choice sits
 * on their current key (ContactKey::$encrypt), where Settings → Contacts' keys
 * has always shown and changed it; one of the person's own addresses has no
 * contact key, so its choice sits in their settings
 * (User::$ownAddressEncrypt). RecipientKeys reads both back into the answer
 * every composer gets, so a composer never needs to know which kind it asked
 * about.
 *
 * An address with neither — no key plMail knows, or only an S/MIME
 * certificate — is refused rather than remembered somewhere else: mail to it
 * goes out unencrypted whatever is set, and a choice kept for a key that
 * might arrive one day would decide about a key nobody has looked at yet.
 */
final readonly class RecipientPolicies
{
    public function __construct(
        private SendingKey             $sendingKeys,
        private ContactKeyRepository   $contactKeys,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ContactKeyRefused when plMail has no key for the address
     */
    public function setEncrypt(User $user, string $email, RecipientPolicy $policy): RecipientPolicy
    {
        $email = strtolower(trim($email));

        if (null !== $this->sendingKeys->for($user, $email)) {
            $user->ownAddressEncrypt = [$email => $policy->value] + $user->ownAddressEncrypt;
        } else {
            $key = $this->contactKeys->findCurrent($user, $email)
                ?? throw new ContactKeyRefused('contact_keys.policy.no_key');

            $key->encrypt = $policy;
        }

        $this->entityManager->flush();

        return $policy;
    }

    /** What is set for one of the person's own addresses; Auto when nothing is. */
    public static function own(User $user, string $email): RecipientPolicy
    {
        return RecipientPolicy::tryFrom($user->ownAddressEncrypt[strtolower(trim($email))] ?? '') ?? RecipientPolicy::Auto;
    }
}
