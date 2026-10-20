<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User\User;
use Normalizer;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The browser-side stretching of a password, seen from the server.
 *
 * A password typed into plMail no longer reaches it. The browser stretches it
 * with PBKDF2-SHA256 into a master key, and sends only a one-iteration PBKDF2
 * of that key — the "auth hash" — which the server then hashes once more like
 * any other password. The master key itself never leaves the browser, and the
 * auth hash cannot be turned back into it. That is the Bitwarden construction,
 * and it is what the end-to-end encryption in issue #28 rests on: the key that
 * will unlock a user's private keys is derived from the same master key, so a
 * server that never sees the password cannot derive it either. See
 * docs/internals/security-model.md, and assets/password_kdf.js for the half
 * that does the work.
 *
 * What lives here is everything the server has to agree with the browser on:
 * the parameters it hands out before a sign-in, the shape of what comes back,
 * and — for the test suite and the console, which have no browser — the same
 * derivation in PHP. derive() must stay byte-for-byte what password_kdf.js
 * computes; PasswordKdfTest pins both to one vector.
 *
 * ## Users who have not moved over yet
 *
 * Everybody whose password was set before this existed, or set by something
 * with no browser to stretch it (the install wizard, an administrator creating
 * them, `app:user:password`, a seed), has a hash of the password itself. Their
 * next sign-in through the form sends the password one last time alongside the
 * auth hash, and PasswordKdfUpgradeListener swaps the stored hash over. The
 * salt for that first auth hash cannot be stored in advance — the prelogin
 * that hands it out is unauthenticated and must not write — so it is computed:
 * placeholderSalt(), an HMAC of the address under the kernel secret. The same
 * function answers for addresses that do not exist at all, which is why an
 * unknown address and a not-yet-moved user get a salt of the same shape.
 */
final readonly class PasswordKdf
{
    /** 128 bits, as RFC 8018 recommends at least 64. */
    public const int SALT_BYTES = 16;

    /** One SHA-256 block: what the browser sends, and what the master key is. */
    public const int HASH_BYTES = 32;

    public function __construct(
        #[Autowire('%app.password_kdf_iterations%')]
        public int $iterations,
        #[Autowire('%kernel.secret%')]
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    /**
     * What a browser needs before it can sign somebody in.
     *
     * `migrate` is the one field that tells an unknown address from a known
     * one, and it does so only for accounts that have not signed in since this
     * shipped — see the "Things that bite" section of docs/internals/security-model.md.
     * The other two fields are deliberately the same shape whoever asks.
     *
     * @return array{salt: string, iterations: int, migrate: bool}
     */
    public function parametersFor(?User $user, string $email): array
    {
        if (null !== $user && null !== $user->kdfSalt && null !== $user->kdfIterations) {
            return ['salt' => $user->kdfSalt, 'iterations' => $user->kdfIterations, 'migrate' => false];
        }

        return [
            'salt'       => $this->placeholderSalt($email),
            'iterations' => $this->iterations,
            // A removed account has an empty hash and can never sign in, so it
            // is answered as the stranger it now is.
            'migrate'    => null !== $user && false === $user->isDeleted(),
        ];
    }

    /**
     * The salt for an address that has none stored, base64.
     *
     * Deterministic, so that asking twice gives the same answer — a random one
     * would tell anybody who asked twice that the address is unknown. Keyed,
     * so that it cannot be computed without the server's secret.
     */
    public function placeholderSalt(string $email): string
    {
        $address = mb_strtolower(trim($email));

        return base64_encode(substr(hash_hmac('sha256', 'plmail/password-kdf-salt|' . $address, $this->secret, true), 0, self::SALT_BYTES));
    }

    public function isSalt(string $value): bool
    {
        return self::SALT_BYTES === strlen((string) base64_decode($value, true)) && base64_encode((string) base64_decode($value, true)) === $value;
    }

    public function isAuthHash(string $value): bool
    {
        return self::HASH_BYTES === strlen((string) base64_decode($value, true)) && base64_encode((string) base64_decode($value, true)) === $value;
    }

    /**
     * The auth hash for a password, exactly as the browser computes it.
     *
     * NFC first, because the same password typed on two keyboards is not
     * always the same bytes: "ü" arrives precomposed from one and as "u" plus
     * a combining diaeresis from another, and a sign-in that works on the
     * laptop and not on the phone would be put down to a typo.
     */
    public static function derive(#[SensitiveParameter] string $password, string $salt, int $iterations): string
    {
        $secret    = (string) Normalizer::normalize($password, Normalizer::FORM_C);
        $masterKey = hash_pbkdf2('sha256', $secret, (string) base64_decode($salt, true), $iterations, self::HASH_BYTES, true);

        return base64_encode(hash_pbkdf2('sha256', $masterKey, $secret, 1, self::HASH_BYTES, true));
    }
}
