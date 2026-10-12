<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\User;
use App\Service\Crypto\Keyring;
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
 *
 * The keyring changes in the same flush. A user key is wrapped under the
 * password, so a new password either comes with every active key re-wrapped
 * under it — the browser does that in Settings, where it knows the old one —
 * or locks them, which is what a password nobody here chose does (the forced
 * form, after somebody else set the last one). A password and the keys hanging
 * off it are never written apart.
 */
final readonly class PasswordChanger
{
    public function __construct(
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface      $entityManager,
        private Keyring                     $keyring,
    ) {
    }

    /**
     * @param FormInterface                  $newPassword   a submitted, valid NewPasswordType
     * @param array<int|string, mixed>|null $rewrappedKeys user key id => the key wrapped under the
     *                                                      new password; null when nothing could be
     *                                                      carried over and the keys are to be locked
     *
     * @throws \App\Domain\Exception\KeyringRefused when a key the person can open was not re-wrapped;
     *                                               nothing is written then
     */
    public function applyFromForm(User $user, FormInterface $newPassword, ?array $rewrappedKeys): void
    {
        $this->apply(
            $user,
            (string) $newPassword->get('authHash')->getData(),
            (string) $newPassword->get('salt')->getData(),
            (int) $newPassword->get('iterations')->getData(),
            $rewrappedKeys,
        );
    }

    /**
     * @param array<int|string, mixed>|null $rewrappedKeys see applyFromForm()
     */
    public function apply(User $user, #[SensitiveParameter] string $authHash, string $salt, int $iterations, ?array $rewrappedKeys): void
    {
        if (null === $rewrappedKeys) {
            $this->keyring->lockAll($user);
        } else {
            $this->keyring->applyPasswordChange($user, $rewrappedKeys);
        }

        $user->useClientDerivedPassword($this->hasher->hashPassword($user, $authHash), $salt, $iterations);
        $user->passwordChangeRequired = false;

        $this->entityManager->flush();
    }
}
