<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Moves an account from a hash of its password to a hash of the auth hash a
 * browser stretched from it — once the password has been proven.
 *
 * Two callers prove it two ways: PasswordKdfUpgradeListener on a sign-in, where
 * the firewall has just checked the password, and UnlockController, where a
 * person already signed in types it to open their keyring. Both then need the
 * same three things done the same way, which is why this is not two copies.
 *
 * The salt is the placeholder the prelogin handed out for the address, because
 * that is what the browser stretched the auth hash with.
 */
final readonly class PasswordKdfUpgrader
{
    public function __construct(
        private PasswordKdf                 $kdf,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface      $entityManager,
    ) {
    }

    /**
     * @return bool whether the account moved — false when it already had, or
     *              when what came is not an auth hash
     */
    public function upgrade(User $user, #[SensitiveParameter] string $authHash, string $email): bool
    {
        if (true === $user->hasClientDerivedPassword() || false === $this->kdf->isAuthHash($authHash)) {
            return false;
        }

        $user->useClientDerivedPassword(
            $this->hasher->hashPassword($user, $authHash),
            $this->kdf->placeholderSalt($email),
            $this->kdf->iterations,
        );

        $this->entityManager->flush();

        return true;
    }
}
