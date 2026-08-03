<?php

declare(strict_types=1);

namespace App\Tests\Service\User\TwoFactor;

use App\Entity\User\TrustedDevice;
use App\Entity\User\User;
use App\Repository\User\TrustedDeviceRepository;
use App\Service\User\TwoFactor\TwoFactorEnrolment;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Enrolment end to end, against the real database.
 *
 * Codes are generated here with otphp rather than stubbed, so this also
 * asserts the thing a unit test cannot: that the configuration plMail writes
 * into the QR and the configuration it later validates against are the same
 * one. Getting those out of step is the classic TOTP bug — it scans cleanly
 * and then rejects every code.
 *
 * Everything runs inside a transaction that is rolled back, so the suite can
 * be re-run without the seeded user drifting.
 */
final class TwoFactorEnrolmentTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (null !== $this->em && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        $this->em = null;

        parent::tearDown();
    }

    private function enrolment(): TwoFactorEnrolment
    {
        return static::getContainer()->get(TwoFactorEnrolment::class);
    }

    private function devices(): TrustedDeviceRepository
    {
        return static::getContainer()->get(TrustedDeviceRepository::class);
    }

    /**
     * A user that exists in the database, since enrolment flushes.
     */
    private function user(): User
    {
        $user = new User();
        $user->email = '2fa-'.bin2hex(random_bytes(6)).'@plmail.test';
        $user->password = 'irrelevant';
        $user->nameFirst = 'Two';
        $user->nameLast = 'Factor';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** The code the user's app would be showing right now. */
    private function currentCode(User $user): string
    {
        return TOTP::create($user->totpSecret, 30, 'sha1', 6)->now();
    }

    public function testBeginStagesASecretWithoutEnablingAnything(): void
    {
        $user = $this->user();

        $uri = $this->enrolment()->begin($user);

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertNotNull($user->totpSecret);
        self::assertFalse($user->isTotpAuthenticationEnabled());
    }

    /**
     * Reopening the panel keeps the staged secret.
     *
     * The enrolment panel re-renders on every rejected code, and its view data
     * calls begin() again. Minting a fresh secret there changed the QR under a
     * user who had already scanned it, so their retry failed for a reason
     * nothing on screen explained — and so did every attempt after.
     */
    public function testBeginKeepsAnUnconfirmedSecret(): void
    {
        $user = $this->user();

        $this->enrolment()->begin($user);
        $first = $user->totpSecret;

        $this->enrolment()->begin($user);

        self::assertSame($first, $user->totpSecret);
    }

    /**
     * But a *confirmed* one is replaced: enrolling again is a deliberate act
     * of replacing the second factor, not resuming the last attempt.
     */
    public function testBeginReplacesAConfirmedSecret(): void
    {
        $user = $this->user();
        $this->enrolment()->begin($user);
        $this->enrolment()->confirm($user, $this->currentCode($user));

        $confirmed = $user->totpSecret;

        $this->enrolment()->begin($user);

        self::assertNotSame($confirmed, $user->totpSecret);
        self::assertFalse($user->isTotpAuthenticationEnabled());
    }

    /**
     * A retry after a wrong code still works — the end-to-end version of the
     * two tests above, and the shape the bug actually presented in.
     */
    public function testAWrongCodeDoesNotBreakTheNextAttempt(): void
    {
        $user = $this->user();
        $this->enrolment()->begin($user);

        self::assertNull($this->enrolment()->confirm($user, '000000'));

        // What the panel does when it re-renders after the rejection.
        $this->enrolment()->begin($user);

        self::assertNotNull($this->enrolment()->confirm($user, $this->currentCode($user)));
        self::assertTrue($user->isTotpAuthenticationEnabled());
    }

    /**
     * The whole point of the staged-but-unconfirmed state: walking away from
     * enrolment must leave an account that still opens with a password.
     */
    public function testAWrongCodeLeavesTwoFactorOff(): void
    {
        $user = $this->user();
        $this->enrolment()->begin($user);

        self::assertNull($this->enrolment()->confirm($user, '000000'));
        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertSame(0, $user->backupCodeCount);
    }

    public function testARealCodeEnablesTwoFactorAndReturnsRecoveryCodes(): void
    {
        $user = $this->user();
        $this->enrolment()->begin($user);

        $codes = $this->enrolment()->confirm($user, $this->currentCode($user));

        self::assertNotNull($codes);
        self::assertCount(User::BACKUP_CODE_COUNT, $codes);
        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertSame(User::BACKUP_CODE_COUNT, $user->backupCodeCount);
    }

    public function testEveryReturnedRecoveryCodeActuallyWorks(): void
    {
        $user = $this->user();
        $this->enrolment()->begin($user);

        $codes = $this->enrolment()->confirm($user, $this->currentCode($user));

        foreach ($codes as $code) {
            self::assertTrue($user->isBackupCode($code), "recovery code $code was not accepted");
        }
    }

    public function testRecoveryCodesAreUnique(): void
    {
        $user = $this->user();
        $this->enrolment()->begin($user);

        $codes = $this->enrolment()->confirm($user, $this->currentCode($user));

        self::assertSame($codes, array_unique($codes));
    }

    public function testRegeneratingReplacesTheWholeSet(): void
    {
        $user = $this->user();
        $this->enrolment()->begin($user);
        $old = $this->enrolment()->confirm($user, $this->currentCode($user));

        $new = $this->enrolment()->regenerateBackupCodes($user);

        self::assertSame(User::BACKUP_CODE_COUNT, $user->backupCodeCount);

        foreach ($old as $code) {
            self::assertFalse($user->isBackupCode($code), 'an old recovery code survived regeneration');
        }

        foreach ($new as $code) {
            self::assertTrue($user->isBackupCode($code));
        }
    }

    public function testDisablingClearsEverything(): void
    {
        $user = $this->user();
        $this->enrolment()->begin($user);
        $codes = $this->enrolment()->confirm($user, $this->currentCode($user));

        $this->enrolment()->disable($user);

        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->totpSecret);
        self::assertFalse($user->isBackupCode($codes[0]));
    }

    /**
     * The invariant that makes "turn it off and on again" safe: a device
     * trusted under the old secret must not keep skipping the prompt.
     */
    public function testDisablingWithdrawsEveryTrustedDevice(): void
    {
        $user = $this->user();
        $this->enrolment()->begin($user);
        $this->enrolment()->confirm($user, $this->currentCode($user));

        ['device' => $device, 'secret' => $secret] = TrustedDevice::create(
            $user,
            'main',
            'Firefox on macOS',
            new DateTimeImmutable('+30 days'),
        );
        $this->em->persist($device);
        $this->em->flush();

        self::assertNotNull($this->devices()->findActiveBySecret($secret, $user, 'main'));

        $this->enrolment()->disable($user);
        $this->em->clear();

        self::assertNull(
            $this->devices()->findActiveBySecret($secret, $user, 'main'),
            'a device trusted under the old secret survived 2FA being turned off',
        );
    }

    /**
     * A revoked grant stops resolving on the very next lookup. This is the
     * property the whole DB-backed design exists for — scheb's stock signed
     * cookie cannot do it.
     */
    public function testRevokingADeviceTakesEffectImmediately(): void
    {
        $user = $this->user();

        ['device' => $device, 'secret' => $secret] = TrustedDevice::create(
            $user,
            'main',
            'Firefox on macOS',
            new DateTimeImmutable('+30 days'),
        );
        $this->em->persist($device);
        $this->em->flush();

        self::assertNotNull($this->devices()->findActiveBySecret($secret, $user, 'main'));

        $device->revoke();
        $this->em->flush();

        self::assertNull($this->devices()->findActiveBySecret($secret, $user, 'main'));
    }

    /**
     * A cookie left behind by whoever used this browser last must not skip the
     * prompt for the person signing in now.
     */
    public function testAGrantDoesNotResolveForADifferentUser(): void
    {
        $owner = $this->user();
        $other = $this->user();

        ['device' => $device, 'secret' => $secret] = TrustedDevice::create(
            $owner,
            'main',
            'Firefox on macOS',
            new DateTimeImmutable('+30 days'),
        );
        $this->em->persist($device);
        $this->em->flush();

        self::assertNull($this->devices()->findActiveBySecret($secret, $other, 'main'));
    }

    /** A grant issued for the web login must not be honoured elsewhere. */
    public function testAGrantDoesNotResolveForADifferentFirewall(): void
    {
        $user = $this->user();

        ['device' => $device, 'secret' => $secret] = TrustedDevice::create(
            $user,
            'main',
            'Firefox on macOS',
            new DateTimeImmutable('+30 days'),
        );
        $this->em->persist($device);
        $this->em->flush();

        self::assertNull($this->devices()->findActiveBySecret($secret, $user, 'jmap'));
    }

    public function testAnExpiredGrantDoesNotResolve(): void
    {
        $user = $this->user();

        ['device' => $device, 'secret' => $secret] = TrustedDevice::create(
            $user,
            'main',
            'Firefox on macOS',
            new DateTimeImmutable('-1 hour'),
        );
        $this->em->persist($device);
        $this->em->flush();

        self::assertNull($this->devices()->findActiveBySecret($secret, $user, 'main'));
    }

    public function testRevokeAllWithdrawsEveryGrantForThatUserOnly(): void
    {
        $user = $this->user();
        $other = $this->user();

        $secrets = [];

        foreach (['Laptop', 'Phone'] as $label) {
            ['device' => $device, 'secret' => $secret] = TrustedDevice::create(
                $user,
                'main',
                $label,
                new DateTimeImmutable('+30 days'),
            );
            $this->em->persist($device);
            $secrets[] = $secret;
        }

        ['device' => $otherDevice, 'secret' => $otherSecret] = TrustedDevice::create(
            $other,
            'main',
            'Someone else',
            new DateTimeImmutable('+30 days'),
        );
        $this->em->persist($otherDevice);
        $this->em->flush();

        $this->devices()->revokeAllForUser($user);
        $this->em->clear();

        foreach ($secrets as $secret) {
            self::assertNull($this->devices()->findActiveBySecret($secret, $user, 'main'));
        }

        self::assertNotNull(
            $this->devices()->findActiveBySecret($otherSecret, $other, 'main'),
            'revoking one user\'s devices withdrew another user\'s',
        );
    }

    // ── proving possession ────────────────────────────────────────────────────

    /**
     * The ordinary case: whoever is holding the authenticator may turn 2FA off
     * or reissue their recovery codes.
     */
    public function testACurrentTotpCodeProvesPossession(): void
    {
        $user = $this->user();
        $enrolment = $this->enrolment();
        $enrolment->begin($user);
        $enrolment->confirm($user, $this->currentCode($user));

        self::assertTrue($enrolment->provesPossession($user, $this->currentCode($user)));
    }

    /**
     * A user who has lost their authenticator still has to be able to turn 2FA
     * off. That is what the recovery codes are for, and refusing one here would
     * lock them out of their own account permanently.
     */
    public function testAnUnspentRecoveryCodeProvesPossession(): void
    {
        $user = $this->user();
        $enrolment = $this->enrolment();
        $enrolment->begin($user);
        $codes = $enrolment->confirm($user, $this->currentCode($user));

        self::assertIsArray($codes);
        self::assertTrue($enrolment->provesPossession($user, $codes[0]));
    }

    /**
     * The one that matters: a recovery code is spent on the way past, so a
     * leaked one cannot be replayed against a second action — disable 2FA
     * *and* reissue the codes, say.
     */
    public function testARecoveryCodeCannotBeUsedTwice(): void
    {
        $user = $this->user();
        $enrolment = $this->enrolment();
        $enrolment->begin($user);
        $codes = $enrolment->confirm($user, $this->currentCode($user));

        self::assertIsArray($codes);
        $enrolment->provesPossession($user, $codes[0]);

        self::assertFalse($enrolment->provesPossession($user, $codes[0]));
        // And only that one is spent; the rest are still the user's way back in.
        self::assertTrue($enrolment->provesPossession($user, $codes[1]));
    }

    public function testAWrongCodeProvesNothingAndSpendsNothing(): void
    {
        $user = $this->user();
        $enrolment = $this->enrolment();
        $enrolment->begin($user);
        $codes = $enrolment->confirm($user, $this->currentCode($user));

        self::assertIsArray($codes);
        self::assertFalse($enrolment->provesPossession($user, 'not-a-code-at-all'));
        self::assertCount(User::BACKUP_CODE_COUNT, $user->backupCodes);
    }
}
