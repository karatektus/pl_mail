<?php

declare(strict_types=1);

namespace App\Tests\Support\Crypto;

use App\Domain\Enum\Crypto\AddressKeyOrigin;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\UserKey;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * People with real OpenPGP keys on the domain the test environment's Web Key
 * Directory serves (`plmail.test`, from .env.test), for the WKD tests.
 *
 * The public half is a real key from gpg (Gpg), because what the directory
 * hands out is reduced by gpg and read back by gpg; the private half and the
 * wrapping are placeholders, since nothing on the server ever opens them.
 */
trait SeedsWkdPeople
{
    abstract private function entityManager(): EntityManagerInterface;

    /**
     * A person whose sign-in address is also their mailbox's, so it is the one
     * address the directory may publish.
     *
     * @return array{0: User, 1: UserKey}
     */
    private function seedWkdPerson(?string $local = null, ?string $extraAddress = null, string $domain = 'plmail.test'): array
    {
        $em    = $this->entityManager();
        $email = ($local ?? 'wkd-' . bin2hex(random_bytes(5))) . '@' . $domain;

        $user = new User();
        $user->email     = $email;
        $user->nameFirst = 'Wkd';
        $user->nameLast  = 'Person';
        $user->roles     = [User::ROLE_USER];
        $user->password  = 'not-a-hash';
        $em->persist($user);

        foreach (array_filter([$email, $extraAddress]) as $address) {
            $account           = new Account();
            $account->usr      = $user;
            $account->email    = $address;
            $account->username = $address;
            $account->authType = 'password';
            $account->isActive = true;
            $account->imapHost = 'imap.example.test';
            $em->persist($account);
        }

        $userKey = new UserKey($user, 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48)));
        $em->persist($userKey);
        $em->flush();

        return [$user, $userKey];
    }

    /** A real key for $email, bound to it as the primary one. */
    private function seedWkdKey(User $user, UserKey $userKey, Gpg $gpg, string $email, ?DateTimeImmutable $expiresAt = null): AddressKey
    {
        $key = new AddressKey(
            $user,
            $userKey,
            $gpg->fingerprint,
            $gpg->armored,
            "-----BEGIN PGP PRIVATE KEY BLOCK-----\nplaceholder\n-----END PGP PRIVATE KEY BLOCK-----\n",
            'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48)),
            AddressKeyOrigin::Imported,
            $expiresAt,
            null,
        );
        $key->bind($email, true);

        $this->entityManager()->persist($key);
        $this->entityManager()->flush();

        return $key;
    }
}
