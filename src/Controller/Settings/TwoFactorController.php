<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Repository\User\TrustedDeviceRepository;
use App\Security\TwoFactor\TrustedDeviceCookieJar;
use App\Service\User\TwoFactor\TwoFactorEnrolment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turning two-factor authentication on and off, and withdrawing trusted
 * devices.
 *
 * Every action here is a POST behind a CSRF token, including the ones that only
 * take something away: a cross-site GET that quietly switched off somebody's
 * second factor would be a more useful attack than most of what 2FA defends
 * against.
 *
 * ── Why the recovery codes go through the flash bag ──────────────────────
 * They are shown exactly once and only their digests are stored, so the
 * response that mints them is the one and only chance to display them. That
 * response is a redirect — a POST that rendered a page directly would re-mint
 * a fresh set on every refresh, invalidating the ones the user was in the
 * middle of writing down. The flash bag is read-once and lives in a session
 * that is already authenticated, which makes it the smallest place to put them
 * for the length of one redirect.
 */
#[Route('/settings/security', name: 'app_two_factor_')]
#[IsGranted('ROLE_USER')]
final class TwoFactorController extends AbstractController
{
    use ChecksCsrf;

    /** Flash key carrying freshly minted recovery codes across the redirect. */
    public const string FLASH_BACKUP_CODES = 'two_factor_backup_codes';

    public function __construct(
        private readonly TwoFactorEnrolment $enrolment,
        private readonly TrustedDeviceRepository $trustedDevices,
        private readonly TrustedDeviceCookieJar $cookies,
        private readonly EntityManagerInterface $em,
        // The flash bag on this install holds sentences, not keys: the toast
        // region in _layout/app.html.twig prints what it finds verbatim, so a
        // key put in here reaches the user as "two_factor.flash.code_rejected".
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Finish enrolment: check a code minted from the staged secret, and switch
     * 2FA on if it holds.
     */
    #[Route('/confirm', name: 'confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        $this->assertCsrf($request, 'two-factor-confirm');

        /** @var User $user */
        $user = $this->getUser();

        $codes = $this->enrolment->confirm($user, (string) $request->request->get('code', ''));

        if (null === $codes) {
            $this->addFlash('error', $this->translator->trans('two_factor.flash.code_rejected'));

            // Back to the panel with the secret still staged, so the user can
            // try again against the QR they already scanned.
            return $this->redirectToSection(['enrol' => '1']);
        }

        $this->addFlash('success', $this->translator->trans('two_factor.flash.enabled'));
        $request->getSession()->getFlashBag()->set(self::FLASH_BACKUP_CODES, $codes);

        return $this->redirectToSection();
    }

    /**
     * Turn 2FA off. Requires a current code or a recovery code: whoever is
     * sitting at an unlocked session should not be able to strip the second
     * factor off the account without holding the second factor.
     */
    #[Route('/disable', name: 'disable', methods: ['POST'])]
    public function disable(Request $request): Response
    {
        $this->assertCsrf($request, 'two-factor-disable');

        /** @var User $user */
        $user = $this->getUser();

        if (false === $user->isTotpAuthenticationEnabled()) {
            return $this->redirectToSection();
        }

        if (false === $this->enrolment->provesPossession($user, (string) $request->request->get('code', ''))) {
            $this->addFlash('error', $this->translator->trans('two_factor.flash.code_rejected'));

            return $this->redirectToSection();
        }

        $this->enrolment->disable($user);
        $this->addFlash('success', $this->translator->trans('two_factor.flash.disabled'));

        return $this->redirectToSection();
    }

    #[Route('/backup-codes', name: 'regenerate_codes', methods: ['POST'])]
    public function regenerateBackupCodes(Request $request): Response
    {
        $this->assertCsrf($request, 'two-factor-backup-codes');

        /** @var User $user */
        $user = $this->getUser();

        if (false === $user->isTotpAuthenticationEnabled()) {
            return $this->redirectToSection();
        }

        if (false === $this->enrolment->provesPossession($user, (string) $request->request->get('code', ''))) {
            $this->addFlash('error', $this->translator->trans('two_factor.flash.code_rejected'));

            return $this->redirectToSection();
        }

        $codes = $this->enrolment->regenerateBackupCodes($user);

        $this->addFlash('success', $this->translator->trans('two_factor.flash.codes_regenerated'));
        $request->getSession()->getFlashBag()->set(self::FLASH_BACKUP_CODES, $codes);

        return $this->redirectToSection();
    }

    #[Route('/devices/{id}/revoke', name: 'revoke_device', methods: ['POST'])]
    public function revokeDevice(Request $request, int $id): Response
    {
        $this->assertCsrf($request, 'two-factor-revoke-device'.$id);

        $device = $this->trustedDevices->findOneOwnedBy($id, $this->getUser());

        if (null === $device) {
            throw $this->createNotFoundException('No such trusted device.');
        }

        $device->revoke();
        $this->em->flush();

        // Revoking the device you are sitting at: drop the cookie too, so the
        // browser stops presenting a secret that will never be honoured again.
        if (true === hash_equals($device->tokenHash, $this->cookies->currentHash($request) ?? '')) {
            $this->cookies->clear($request);
        }

        $this->addFlash('success', $this->translator->trans('two_factor.flash.device_revoked'));

        return $this->redirectToSection();
    }

    #[Route('/devices/revoke-all', name: 'revoke_all_devices', methods: ['POST'])]
    public function revokeAllDevices(Request $request): Response
    {
        $this->assertCsrf($request, 'two-factor-revoke-all-devices');

        $this->trustedDevices->revokeAllForUser($this->getUser());
        $this->cookies->clear($request);

        $this->addFlash('success', $this->translator->trans('two_factor.flash.devices_revoked'));

        return $this->redirectToSection();
    }

    /**
     * @param array<string, string> $extra
     */
    private function redirectToSection(array $extra = []): Response
    {
        return $this->redirectToRoute('app_settings_index', ['section' => 'security', ...$extra]);
    }
}
