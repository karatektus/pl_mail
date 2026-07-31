<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\User\ApiTokenRepository;
use App\Repository\User\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A logged-in session is not enough to change state — a token is required too.
 *
 * The app-password and alias endpoints read the request bag directly and
 * checked nothing, so any page the user visited could mint them a working app
 * password or add a send-as address. The add forms now go through
 * ApiTokenType/EmailAliasType and the single-button actions carry a token by
 * hand; this pins both down.
 */
final class StateChangingPostsNeedCsrfTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    /**
     * Field names must be the ones the form actually reads — a payload the form
     * ignores would pass this test whether or not the token is checked.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function endpoints(): iterable
    {
        yield 'mint an app password' => [
            '/settings/app-passwords/create',
            ['api_token' => ['name' => 'forged']],
        ];
    }

    #[DataProvider('endpoints')]
    public function testATokenlessPostDoesNotWrite(string $path, array $payload): void
    {
        $client = static::createClient();

        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        $tokens = static::getContainer()->get(ApiTokenRepository::class);
        $before = count($tokens->findForUser($user));

        $client->request('POST', $path, $payload);

        self::assertCount(
            $before,
            $tokens->findForUser($user),
            "$path wrote without a CSRF token",
        );
    }

    public function testTheRevokeButtonRejectsAForgedToken(): void
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->request('POST', '/settings/app-passwords/1/revoke', ['_token' => 'nonsense']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * The two-factor endpoints, which are worth pinning separately.
     *
     * A cross-site POST that stripped somebody's second factor, or quietly
     * re-trusted a device, would be a more useful attack than most of what 2FA
     * is there to stop — and unlike minting an app password, the damage is
     * invisible until the next sign-in.
     *
     * @return iterable<string, array{string}>
     */
    public static function twoFactorEndpoints(): iterable
    {
        yield 'turn off 2FA' => ['/settings/security/disable'];
        yield 'confirm enrolment' => ['/settings/security/confirm'];
        yield 'regenerate recovery codes' => ['/settings/security/backup-codes'];
        yield 'revoke one device' => ['/settings/security/devices/1/revoke'];
        yield 'revoke every device' => ['/settings/security/devices/revoke-all'];
    }

    #[DataProvider('twoFactorEndpoints')]
    public function testTwoFactorEndpointsRejectAForgedToken(string $path): void
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->request('POST', $path, ['_token' => 'nonsense', 'code' => '000000']);

        self::assertSame(403, $client->getResponse()->getStatusCode(), "$path accepted a forged token");
    }
}
