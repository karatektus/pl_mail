<?php

declare(strict_types=1);

namespace App\Service\User\TwoFactor;

use App\Entity\User\TrustedDevice;
use App\Entity\User\User;
use App\Repository\User\TrustedDeviceRepository;
use App\Security\TwoFactor\TrustedDeviceCookieJar;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Settings → Security section's template parameters.
 *
 * Built here rather than in the controller because three entry points need the
 * same shape: the settings page, the enrolment panel, and every mutation that
 * re-renders the section afterwards.
 *
 * The QR is only generated when the panel is actually open. It is the TOTP
 * secret in scannable form, so rendering it into every settings page view —
 * including the one somebody has left open on a second monitor — would be
 * putting it on screen for no reason.
 */
final readonly class SecuritySectionViewData
{
    public function __construct(
        private TwoFactorEnrolment $enrolment,
        private QrCodeRenderer $qrCodes,
        private TrustedDeviceRepository $trustedDevices,
        private TrustedDeviceCookieJar $cookies,
    ) {
    }

    /**
     * @param list<string>|null $newBackupCodes plaintext codes to show once,
     *                                          straight after they were minted
     *
     * @return array<string, mixed>
     */
    public function build(User $user, Request $request, ?array $newBackupCodes = null): array
    {
        $enrolling = false === $user->isTotpAuthenticationEnabled()
            && '1' === (string) $request->query->get('enrol', '');

        $qrDataUri = null;
        $secret = null;

        if (true === $enrolling) {
            // begin() reuses an unconfirmed secret rather than minting one, so
            // reopening the panel — which is what a rejected code does — shows
            // the same QR the user already scanned.
            $qrDataUri = $this->qrCodes->dataUri($this->enrolment->begin($user));
            $secret = $user->totpSecret;
        }

        $devices = $this->trustedDevices->findActiveForUser($user);

        return [
            'twoFactorEnabled'    => $user->isTotpAuthenticationEnabled(),
            'twoFactorEnrolling'  => $enrolling,
            'twoFactorQr'         => $qrDataUri,
            // Shown beside the QR for anyone whose authenticator cannot use a
            // camera, or who is reading this page on the phone itself.
            'twoFactorSecret'     => $secret,
            'twoFactorConfirmedAt' => $user->totpConfirmedAt,
            'backupCodesRemaining' => $user->backupCodeCount,
            'newBackupCodes'      => $newBackupCodes,
            'trustedDevices'      => $devices,
            'currentDeviceId'     => $this->currentDeviceId($devices, $request),
        ];
    }

    /**
     * Which row in the list is the browser reading it.
     *
     * Marking it is the difference between "revoke the one I don't recognise"
     * and accidentally signing yourself out — two rows that both say "Firefox
     * on macOS" are otherwise indistinguishable.
     *
     * @param list<TrustedDevice> $devices
     */
    private function currentDeviceId(array $devices, Request $request): ?int
    {
        $hash = $this->cookies->currentHash($request);

        if (null === $hash) {
            return null;
        }

        foreach ($devices as $device) {
            if (true === hash_equals($device->tokenHash, $hash)) {
                return $device->id;
            }
        }

        return null;
    }
}
