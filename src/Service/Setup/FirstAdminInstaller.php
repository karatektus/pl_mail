<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the account that owns a fresh install.
 *
 * Shared by the /install page and `app:setup`, so the terminal route and the
 * browser route cannot drift apart on what "the first user" means — both go
 * through the same locked write in UserRepository::createFirstAdmin().
 */
final readonly class FirstAdminInstaller
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @return bool false when the install already had a user, i.e. two people
     *              submitted the form at once and this one lost
     */
    public function install(User $user, #[SensitiveParameter] string $plainPassword): bool
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        return $this->users->createFirstAdmin($user);
    }
}
