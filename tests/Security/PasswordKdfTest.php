<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User\User;
use App\Security\PasswordKdf;
use PHPUnit\Framework\TestCase;

/**
 * The server's half of browser-side password stretching agrees with the
 * browser's, and hands out what the browser needs without saying more.
 *
 * derive() is what the test suite and the console use in place of a browser,
 * so if it drifted from assets/password_kdf.js every test built on it would
 * pass against an arithmetic no browser performs. The vector below was
 * computed independently (Python's hashlib) and checked against the
 * JavaScript module; auth.spec.ts closes the loop by signing a browser into an
 * account whose hash was made here.
 */
final class PasswordKdfTest extends TestCase
{
    private const string SALT = 'c2FsdHNhbHRzYWx0c2FsdA==';

    public function testDeriveMatchesTheIndependentlyComputedVector(): void
    {
        self::assertSame(
            'RhhWu3KBQElRsV5ner2qXtn5TAwdYoWNjl2lwSpp9Ik=',
            PasswordKdf::derive('correct horse ü battery', self::SALT, 1000),
        );
    }

    /**
     * The same password typed as "u" plus a combining diaeresis is the same
     * password, rather than a sign-in that works on one keyboard only.
     */
    public function testAPasswordIsNormalisedBeforeItIsStretched(): void
    {
        self::assertSame(
            PasswordKdf::derive("correct horse \u{00FC} battery", self::SALT, 1000),
            PasswordKdf::derive("correct horse u\u{0308} battery", self::SALT, 1000),
        );
    }

    /**
     * Asking twice about an unknown address must give the same answer. A fresh
     * random salt each time would tell anybody who asked twice that nobody by
     * that address exists.
     */
    public function testAnUnknownAddressGetsTheSameSaltEveryTimeRatherThanARandomOne(): void
    {
        $kdf = $this->kdf();

        self::assertSame($kdf->parametersFor(null, 'nobody@example.test'), $kdf->parametersFor(null, 'nobody@example.test'));
        self::assertSame($kdf->placeholderSalt('Nobody@Example.test '), $kdf->placeholderSalt('nobody@example.test'));
        self::assertNotSame($kdf->placeholderSalt('nobody@example.test'), $kdf->placeholderSalt('somebody@example.test'));
        self::assertTrue($kdf->isSalt($kdf->placeholderSalt('nobody@example.test')));
    }

    /**
     * Keyed with the server's secret, so another install — or anybody without
     * the secret — cannot compute it.
     */
    public function testThePlaceholderSaltDependsOnTheServersSecret(): void
    {
        self::assertNotSame(
            $this->kdf('one-secret')->placeholderSalt('nobody@example.test'),
            $this->kdf('another-secret')->placeholderSalt('nobody@example.test'),
        );
    }

    /**
     * An unknown address and a moved account look alike; only an account
     * that has not signed in since the change is told to send its password
     * once more.
     */
    public function testOnlyAnAccountStillOnAHashOfThePasswordIsToldToMigrate(): void
    {
        $kdf = $this->kdf();

        $waiting = new User();
        $waiting->useServerHashedPassword('irrelevant');

        $moved = new User();
        $moved->useClientDerivedPassword('irrelevant', base64_encode(str_repeat('s', 16)), 700000);

        self::assertSame(['salt' => $kdf->placeholderSalt('a@example.test'), 'iterations' => 600000, 'migrate' => false], $kdf->parametersFor(null, 'a@example.test'));
        self::assertSame(['salt' => $kdf->placeholderSalt('a@example.test'), 'iterations' => 600000, 'migrate' => true], $kdf->parametersFor($waiting, 'a@example.test'));
        self::assertSame(['salt' => base64_encode(str_repeat('s', 16)), 'iterations' => 700000, 'migrate' => false], $kdf->parametersFor($moved, 'a@example.test'));
    }

    /**
     * A removed account can never sign in, so it is answered as the stranger
     * it now is rather than as somebody waiting to move over.
     */
    public function testARemovedAccountIsNotToldToMigrate(): void
    {
        $removed = new User();
        $removed->useServerHashedPassword('');
        $removed->deletedAt = new \DateTimeImmutable();

        self::assertFalse($this->kdf()->parametersFor($removed, 'gone@example.test')['migrate']);
    }

    public function testOnlyWellFormedValuesAreTakenForASaltOrAnAuthHash(): void
    {
        $kdf = $this->kdf();

        self::assertTrue($kdf->isAuthHash(PasswordKdf::derive('x', self::SALT, 1)));
        self::assertFalse($kdf->isAuthHash('a plain password'), 'a password was taken for an auth hash');
        self::assertFalse($kdf->isAuthHash(base64_encode(str_repeat('x', 31))));
        self::assertFalse($kdf->isSalt(base64_encode(str_repeat('x', 15))));
        self::assertFalse($kdf->isSalt('not base64 at all!'));
    }

    private function kdf(string $secret = 'test-secret'): PasswordKdf
    {
        return new PasswordKdf(600000, $secret);
    }
}
