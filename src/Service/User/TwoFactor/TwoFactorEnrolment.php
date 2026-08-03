<?php

declare(strict_types=1);

namespace App\Service\User\TwoFactor;

use App\Entity\User\User;
use App\Repository\User\TrustedDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;

/**
 * Turning two-factor authentication on and off for one user.
 *
 * The only place that writes the TOTP secret, the recovery codes, or the
 * confirmation timestamp — which is what keeps the two invariants that matter
 * in one readable place:
 *
 *  1. A secret is never active until a code minted from it has been checked.
 *     Enrolment that is abandoned halfway leaves an account that still opens
 *     with a password, not one nobody can get into.
 *  2. Replacing or removing the second factor withdraws every trusted device.
 *     A device trusted under the old secret would otherwise keep skipping the
 *     prompt, which is the one way "I turned 2FA off and back on" could leave
 *     a machine authorised that the user thinks they revoked.
 */
final readonly class TwoFactorEnrolment
{
    /** Bytes of entropy behind a recovery code. */
    private const int BACKUP_CODE_BYTES = 8;

    public function __construct(
        private TotpAuthenticatorInterface $totpAuthenticator,
        private TrustedDeviceRepository $trustedDevices,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Stage a secret if there is not already an unconfirmed one, and hand back
     * the otpauth:// URI to render.
     *
     * Reusing matters more than it looks. This is called from the view data of
     * the enrolment panel, which re-renders on every rejected code — minting a
     * fresh secret there would silently invalidate the QR the user scanned a
     * moment ago, so their second attempt fails for a reason nothing on screen
     * explains, and so does every attempt after it.
     *
     * Keeping an abandoned one costs nothing: an unconfirmed secret cannot
     * authenticate anything (see User::isTotpAuthenticationEnabled), and both
     * disabling 2FA and confirming a new enrolment replace it.
     */
    public function begin(User $user): string
    {
        if (null === $user->totpSecret || true === $user->isTotpAuthenticationEnabled()) {
            $user->startTotpEnrolment($this->totpAuthenticator->generateSecret());

            $this->entityManager->flush();
        }

        return $this->totpAuthenticator->getQRContent($user);
    }

    public function qrContent(User $user): string
    {
        return $this->totpAuthenticator->getQRContent($user);
    }

    public function verifyCode(User $user, string $code): bool
    {
        if (null === $user->totpSecret) {
            return false;
        }

        return $this->totpAuthenticator->checkCode($user, trim($code));
    }

    /**
     * Finish enrolment: the code checked out, so switch 2FA on and hand back
     * the recovery codes.
     *
     * The plaintext codes are returned once and never recoverable — only their
     * digests are stored. Returns null when the code is wrong, so the caller
     * has one thing to branch on.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $code): ?array
    {
        if (false === $this->verifyCode($user, $code)) {
            return null;
        }

        $user->confirmTotp();
        $codes = $this->replaceBackupCodes($user);

        // Nothing was trusted against this secret yet, but a previous
        // enrolment's devices might still be — see the class note.
        $this->trustedDevices->revokeAllForUser($user);

        $this->entityManager->flush();

        return $codes;
    }

    /**
     * Issue a new set of recovery codes, discarding whatever is left of the
     * old one. Returns the plaintext, shown once.
     *
     * @return list<string>
     */
    public function regenerateBackupCodes(User $user): array
    {
        $codes = $this->replaceBackupCodes($user);

        $this->entityManager->flush();

        return $codes;
    }

    /**
     * Turn 2FA off, discarding the secret, the recovery codes and every
     * trusted device.
     */
    public function disable(User $user): void
    {
        $user->disableTotp();
        $this->trustedDevices->revokeAllForUser($user);

        $this->entityManager->flush();
    }

    /**
     * @return list<string>
     */
    private function replaceBackupCodes(User $user): array
    {
        $codes = [];
        $hashes = [];

        for ($i = 0; $i < User::BACKUP_CODE_COUNT; ++$i) {
            $code = $this->generateBackupCode();
            $codes[] = $code;
            $hashes[] = User::hashBackupCode($code);
        }

        $user->backupCodes = $hashes;

        return $codes;
    }

    /**
     * 64 bits of entropy, shown as two dash-separated groups so it can be read
     * off paper without losing your place. The dashes and the case are
     * stripped before hashing — see User::hashBackupCode().
     */
    private function generateBackupCode(): string
    {
        $hex = bin2hex(random_bytes(self::BACKUP_CODE_BYTES));

        return substr($hex, 0, 4).'-'.substr($hex, 4, 4).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4);
    }
}
