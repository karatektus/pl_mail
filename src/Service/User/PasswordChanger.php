<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use SensitiveParameter;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Stores a password a person chose for themselves in a browser.
 *
 * Two forms end here — changing it from Settings → Security, and choosing one
 * after an administrator chose the last — and they have to do the same three
 * things: hash the auth hash, keep the salt and iteration count it was
 * stretched with, and clear the forced-change flag. A second copy of that in a
 * second controller would be the one that forgets the flag.
 *
 * Clearing the flag on every self-chosen password, not only on the forced
 * form, is deliberate: whichever way the person picked their own, nobody else
 * knows it any more.
 */
final readonly class PasswordChanger
{
    public function __construct(
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface      $entityManager,
    ) {
    }

    /**
     * @param FormInterface $newPassword a submitted, valid NewPasswordType
     */
    public function applyFromForm(User $user, FormInterface $newPassword): void
    {
        $this->apply(
            $user,
            (string) $newPassword->get('authHash')->getData(),
            (string) $newPassword->get('salt')->getData(),
            (int) $newPassword->get('iterations')->getData(),
        );
    }

    public function apply(User $user, #[SensitiveParameter] string $authHash, string $salt, int $iterations): void
    {
        $user->useClientDerivedPassword($this->hasher->hashPassword($user, $authHash), $salt, $iterations);
        $user->passwordChangeRequired = false;

        $this->entityManager->flush();
    }
}
