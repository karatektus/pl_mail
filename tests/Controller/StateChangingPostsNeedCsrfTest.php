<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Repository\User\ApiTokenRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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

    // ── the drag-to-reorder endpoints ─────────────────────────────────────

    /**
     * The two list-ordering endpoints, which take their token in a header.
     *
     * The account one is why this section exists. It was a per-account PATCH
     * driven by @stimulus-components/sortable, a wrapper that builds its own
     * multipart body and gives you nowhere to attach a token — so it shipped
     * with a comment explaining that it had none, and stayed that way. The
     * rules list had already dropped the wrapper for exactly this reason; the
     * accounts list now posts the whole order as JSON the same way, and this
     * test is what stops the next draggable list from repeating it.
     *
     * Asserted as 403 rather than "nothing was written": the order is the thing
     * being written, so a silent no-op and a rejection look the same from the
     * repository, and only one of them is the endpoint doing its job.
     *
     * @return iterable<string, array{string}>
     */
    public static function reorderEndpoints(): iterable
    {
        yield 'reorder the account list' => ['/account/reorder'];
        yield 'reorder the rules list'   => ['/settings/filters/reorder'];
    }

    #[DataProvider('reorderEndpoints')]
    public function testReorderEndpointsRejectARequestWithNoToken(string $path): void
    {
        $client = $this->signedIn();

        $client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => [2, 1]], JSON_THROW_ON_ERROR),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode(), "$path accepted a request with no token");
    }

    #[DataProvider('reorderEndpoints')]
    public function testReorderEndpointsRejectAForgedToken(string $path): void
    {
        $client = $this->signedIn();

        $client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'nonsense'],
            content: json_encode(['ids' => [2, 1]], JSON_THROW_ON_ERROR),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode(), "$path accepted a forged token");
    }

    /**
     * The JSON endpoints behind the appearance settings and the list's
     * new-mail markers, which checked no token at all. Their callers send the
     * layout's `ajax` token as X-CSRF-Token; a request without one is refused.
     *
     * @return iterable<string, array{string}>
     */
    public static function headerTokenEndpoints(): iterable
    {
        yield 'save the appearance'     => ['/settings/appearance'];
        yield 'upload a background'     => ['/settings/appearance/background'];
        yield 'import a theme'          => ['/settings/appearance/import'];
        yield 'reset the appearance'    => ['/settings/appearance/reset'];
        yield 'mark list rows as seen'  => ['/mail/threads/listed'];
    }

    #[DataProvider('headerTokenEndpoints')]
    public function testHeaderTokenEndpointsRejectARequestWithNoToken(string $path): void
    {
        $client = $this->signedIn();

        $client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['version' => 1, 'ids' => [1]], JSON_THROW_ON_ERROR),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode(), "$path accepted a request with no token");
    }

    /**
     * And the endpoint that chooses which account sends mail, which is new and
     * is the more valuable of the two to forge: it silently changes the address
     * everything the user writes afterwards goes out from.
     */
    public function testMakingAnAccountPrimaryRejectsAForgedToken(): void
    {
        $client = $this->signedIn();

        $client->request('POST', '/account/1/primary', ['_token' => 'nonsense']);

        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [403, 404],
            'the primary switch accepted a forged token',
        );
    }

    // ── the compose window's fetch() actions ──────────────────────────────

    /**
     * Undo, unschedule, discard and the three attachment actions are POSTs the
     * compose window makes with fetch(), and none of them checked a token: any
     * page the user visited could discard their drafts or call off a send.
     * They take the shared `ajax` token in the X-CSRF-Token header now.
     *
     * Against a real draft, so the answer is the token check and not a 404.
     *
     * @return iterable<string, array{string}>
     */
    public static function composeEndpoints(): iterable
    {
        yield 'undo a send'          => ['/compose/undo/{message}'];
        yield 'unschedule a send'    => ['/compose/unschedule/{message}'];
        yield 'discard a draft'      => ['/compose/discard/{message}'];
        yield 'attach files'         => ['/compose/attachments/{message}'];
        yield 'insert inline image'  => ['/compose/inline-image/{message}'];
        yield 'remove an attachment' => ['/compose/attachment/{part}/remove'];
    }

    #[DataProvider('composeEndpoints')]
    public function testComposeActionsRejectARequestWithNoToken(string $path): void
    {
        $client = $this->signedIn();
        $client->disableReboot();

        $container  = static::getContainer();
        $em         = $container->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $user       = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        $connection->beginTransaction();

        try {
            $account           = new Account();
            $account->usr      = $user;
            $account->email    = 'csrf-fixture@joder.dev';
            $account->username = 'csrf-fixture@joder.dev';
            $account->authType = 'password';
            $account->isActive = true;
            $account->imapHost = 'imap.example.test';
            $em->persist($account);

            $draft                 = new Message();
            $draft->account        = $account;
            $draft->subject        = 'CSRF fixture';
            $draft->hasAttachments = true;
            $draft->flags          = [MessageFlag::DRAFT->value];
            $em->persist($draft);

            $part              = new MessagePart();
            $part->message     = $draft;
            $part->contentType = 'text/plain';
            $part->disposition = 'attachment';
            $part->filename    = 'fixture.txt';
            $em->persist($part);
            $em->flush();

            $client->request('POST', strtr($path, [
                '{message}' => (string) $draft->id,
                '{part}'    => (string) $part->id,
            ]));

            self::assertSame(403, $client->getResponse()->getStatusCode(), "$path accepted a request with no token");
        } finally {
            $connection->rollBack();
        }
    }

    private function signedIn(): KernelBrowser
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        return $client;
    }
}
