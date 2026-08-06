<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Push;

/**
 * The two files an admin pastes, minted rather than checked in.
 *
 * The service-account key carries a REAL RSA key, generated here, because the
 * one thing worth proving about the token grant is that the assertion verifies
 * — and a placeholder string would let a broken signature pass as long as the
 * shape was right. Generated once per process and reused: 2048-bit keygen is
 * the slowest thing in these tests by an order of magnitude.
 *
 * Shared by the sender, the grant and the admin-form tests, so all three agree
 * on what a valid pair looks like.
 */
final class FirebaseFixture
{
    private static ?string $privateKey = null;

    private static ?string $publicKey = null;

    /** PEM private key, matching publicKey(). */
    public static function privateKey(): string
    {
        self::mint();

        return (string) self::$privateKey;
    }

    /** PEM public key, for verifying a signature the provider produced. */
    public static function publicKey(): string
    {
        self::mint();

        return (string) self::$publicKey;
    }

    public static function serviceAccountJson(string $projectId = 'plmail-test'): string
    {
        return (string) json_encode([
            'type'          => 'service_account',
            'project_id'    => $projectId,
            'private_key_id' => 'abc123',
            'private_key'   => self::privateKey(),
            'client_email'  => 'push@' . $projectId . '.iam.gserviceaccount.com',
            'client_id'     => '1234567890',
            'token_uri'     => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * A google-services.json registering one Android client, optionally under a
     * package other than the one plMail ships.
     */
    public static function googleServicesJson(
        string $projectId = 'plmail-test',
        string $package = 'de.plmail.google',
    ): string {
        return (string) json_encode([
            'project_info' => [
                'project_number' => '1234567890',
                'project_id'     => $projectId,
                'storage_bucket' => $projectId . '.appspot.com',
            ],
            'client' => [
                [
                    'client_info' => [
                        'mobilesdk_app_id'    => '1:1234567890:android:0123456789abcdef',
                        'android_client_info' => ['package_name' => $package],
                    ],
                    'api_key' => [['current_key' => 'AIzaSyTestKeyForPlMail']],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private static function mint(): void
    {
        if (null !== self::$privateKey) {
            return;
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (false === $key) {
            throw new \RuntimeException('Could not generate a test RSA key: ' . (string) openssl_error_string());
        }

        $private = '';
        openssl_pkey_export($key, $private);

        $details = openssl_pkey_get_details($key);

        if (false === $details) {
            throw new \RuntimeException('Could not read the generated key back: ' . (string) openssl_error_string());
        }

        self::$privateKey = $private;
        self::$publicKey  = (string) $details['key'];
    }
}
