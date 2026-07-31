<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Entity\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two-factor state machine on User.
 *
 * The invariants here are the ones that decide whether somebody can get into
 * their mail, so they are asserted directly rather than only through the
 * enrolment service that normally drives them.
 */
final class TwoFactorTest extends TestCase
{
    public function testTwoFactorIsOffOnAFreshUser(): void
    {
        self::assertFalse((new User())->isTotpAuthenticationEnabled());
    }

    /**
     * The invariant that keeps an abandoned enrolment from locking an account:
     * a secret exists, but nothing has proved it works, so the login is still
     * the password alone.
     */
    public function testAStagedSecretDoesNotEnableTwoFactor(): void
    {
        $user = new User();
        $user->startTotpEnrolment('JBSWY3DPEHPK3PXP');

        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->getTotpConfirmedAt());
    }

    public function testConfirmingEnablesTwoFactor(): void
    {
        $user = new User();
        $user->startTotpEnrolment('JBSWY3DPEHPK3PXP');
        $user->confirmTotp();

        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertNotNull($user->getTotpConfirmedAt());
    }

    public function testRestartingEnrolmentUnconfirmsTheOldSecret(): void
    {
        $user = new User();
        $user->startTotpEnrolment('JBSWY3DPEHPK3PXP');
        $user->confirmTotp();

        $user->startTotpEnrolment('MFRGGZDFMZTWQ2LK');

        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertSame('MFRGGZDFMZTWQ2LK', $user->getTotpSecret());
    }

    public function testTotpConfigurationIsNullWithoutASecret(): void
    {
        self::assertNull((new User())->getTotpAuthenticationConfiguration());
    }

    /**
     * Google Authenticator ignores the algorithm and digit parameters and
     * assumes SHA-1 and 6 digits. Anything else here scans cleanly and then
     * rejects every code — see the constants on User.
     */
    public function testTotpConfigurationUsesRfc6238Defaults(): void
    {
        $user = new User();
        $user->startTotpEnrolment('JBSWY3DPEHPK3PXP');

        $config = $user->getTotpAuthenticationConfiguration();

        self::assertNotNull($config);
        self::assertSame('sha1', $config->getAlgorithm());
        self::assertSame(30, $config->getPeriod());
        self::assertSame(6, $config->getDigits());
        self::assertSame('JBSWY3DPEHPK3PXP', $config->getSecret());
    }

    /**
     * Recovery codes are a second proof of the same factor. Leaving them
     * behind would still open the account after the user believes they have
     * removed 2FA.
     */
    public function testDisablingClearsTheSecretAndTheRecoveryCodes(): void
    {
        $user = new User();
        $user->startTotpEnrolment('JBSWY3DPEHPK3PXP');
        $user->confirmTotp();
        $user->setBackupCodeHashes([User::hashBackupCode('aaaa-bbbb-cccc-dddd')]);

        $user->disableTotp();

        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->getTotpSecret());
        self::assertSame(0, $user->countBackupCodes());
        self::assertFalse($user->isBackupCode('aaaa-bbbb-cccc-dddd'));
    }

    public function testRecognisesAStoredRecoveryCode(): void
    {
        $user = new User();
        $user->setBackupCodeHashes([User::hashBackupCode('aaaa-bbbb-cccc-dddd')]);

        self::assertTrue($user->isBackupCode('aaaa-bbbb-cccc-dddd'));
        self::assertFalse($user->isBackupCode('0000-0000-0000-0000'));
    }

    /**
     * The codes are displayed with dashes and read off paper, so neither the
     * grouping nor the shift key may decide whether a valid one is accepted.
     */
    #[DataProvider('equivalentSpellings')]
    public function testRecoveryCodesAreMatchedIgnoringCaseAndGrouping(string $entered): void
    {
        $user = new User();
        $user->setBackupCodeHashes([User::hashBackupCode('aaaa-bbbb-cccc-dddd')]);

        self::assertTrue($user->isBackupCode($entered));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentSpellings(): iterable
    {
        yield 'as shown'      => ['aaaa-bbbb-cccc-dddd'];
        yield 'upper case'    => ['AAAA-BBBB-CCCC-DDDD'];
        yield 'no dashes'     => ['aaaabbbbccccdddd'];
        yield 'spaces'        => ['aaaa bbbb cccc dddd'];
        yield 'mixed case'    => ['AaAa-bBbB-CcCc-DdDd'];
    }

    public function testSpendingARecoveryCodeRemovesOnlyThatOne(): void
    {
        $user = new User();
        $user->setBackupCodeHashes([
            User::hashBackupCode('aaaa-bbbb-cccc-dddd'),
            User::hashBackupCode('1111-2222-3333-4444'),
        ]);

        $user->invalidateBackupCode('aaaa-bbbb-cccc-dddd');

        self::assertFalse($user->isBackupCode('aaaa-bbbb-cccc-dddd'));
        self::assertTrue($user->isBackupCode('1111-2222-3333-4444'));
        self::assertSame(1, $user->countBackupCodes());
    }

    /**
     * A spent code must not come back because the user retyped it with
     * different spacing — the same normalisation has to apply on the way out.
     */
    public function testSpendingIsAlsoInsensitiveToSpelling(): void
    {
        $user = new User();
        $user->setBackupCodeHashes([User::hashBackupCode('aaaa-bbbb-cccc-dddd')]);

        $user->invalidateBackupCode('AAAABBBBCCCCDDDD');

        self::assertSame(0, $user->countBackupCodes());
    }

    public function testOnlyDigestsAreStored(): void
    {
        $user = new User();
        $user->setBackupCodeHashes([User::hashBackupCode('aaaa-bbbb-cccc-dddd')]);

        foreach ($user->getBackupCodeHashes() as $hash) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
            self::assertStringNotContainsString('aaaa', $hash);
        }
    }
}
