<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Domain\Enum\Backup\ConfigBackupDisposition;
use App\Entity\User\User;
use App\Service\Backup\ConfigBackupEnvironment;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Signature\Exception\InvalidSignatureException;
use Symfony\Component\Security\Core\Signature\SignatureHasher;

/**
 * What a config backup must NOT let a browser do.
 *
 * A v2 backup carries users — the password hash included — and it used to carry
 * APP_SECRET as well. Those are both halves of what a signature-based
 * remember-me cookie is checked against, so restoring such a file onto a second
 * machine handed that machine's login to every browser still holding a cookie
 * from the first. Nobody chose that; it fell out of an inventory entry.
 *
 * Two stacks on one host is not a hypothetical, which is what made it worth
 * acting on: cookies are not scoped by port, so a browser on localhost sends
 * the same REMEMBERME to whatever answers on :80 and on :8002.
 *
 * The fix is that the secret stops travelling — the target keeps the one it
 * generated at first boot. Both halves of that claim are asserted here, because
 * either alone is satisfied by code that does the wrong thing: the export not
 * carrying it, and the cookie genuinely dying when the secret differs.
 */
final class RememberMeAcrossRestoreTest extends KernelTestCase
{
    /**
     * The enabler, gone: a backup written by this build cannot tell another
     * machine what to sign cookies with, because it does not know.
     */
    public function testTheBackupDoesNotCarryTheAppSecret(): void
    {
        self::bootKernel();

        $environment = static::getContainer()->get(ConfigBackupEnvironment::class);

        self::assertNotContains('APP_SECRET', $environment->variables(), 'APP_SECRET is back in the inventory');
        self::assertArrayNotHasKey(
            'APP_SECRET',
            $environment->export(),
            'this test stack sets APP_SECRET, and the export still picked it up',
        );

        // And an old backup that still carries one is refused rather than
        // written, so upgrading does not quietly leave the hole open.
        self::assertSame(
            ConfigBackupDisposition::KeptDeliberately,
            $environment->dispositionFor('APP_SECRET'),
            'an old backup could still overwrite this install\'s secret',
        );
    }

    /**
     * And the reason that matters: the signature is over the secret, so a
     * cookie minted by the machine the backup came from is refused by the
     * machine it was restored onto — as long as the two secrets differ, which
     * the assertion above is what guarantees.
     *
     * Asserted at the hasher rather than through the firewall because the
     * secret is a compile-time container parameter: two kernels with different
     * ones is two containers, two caches and a much slower test that proves the
     * same single fact about the same single class.
     */
    public function testARememberMeSignatureDoesNotSurviveADifferentAppSecret(): void
    {
        $user           = new User();
        $user->email    = 'remembered@plmail.test';
        $user->password = '$2y$04$notarealhashbutstableenoughforthis';

        $expires = time() + 3600;

        $sourceMachine = $this->hasherWith('the-source-installations-secret');
        $targetMachine = $this->hasherWith('the-target-installations-own-secret');

        $cookieHash = $sourceMachine->computeSignatureHash($user, $expires);

        // Sanity: the cookie is good on the machine that issued it, or the
        // assertion below would pass for the wrong reason.
        $sourceMachine->verifySignatureHash($user, $expires, $cookieHash);

        $this->expectException(InvalidSignatureException::class);

        $targetMachine->verifySignatureHash($user, $expires, $cookieHash);
    }

    /**
     * The same signature properties the firewall's remember_me uses: password,
     * by Symfony's default for a PasswordAuthenticatedUserInterface. That is
     * the other half a v2 backup carries, and it is why the password hash alone
     * was never enough to make this safe.
     */
    private function hasherWith(string $secret): SignatureHasher
    {
        return new SignatureHasher(PropertyAccess::createPropertyAccessor(), ['password'], $secret);
    }
}
