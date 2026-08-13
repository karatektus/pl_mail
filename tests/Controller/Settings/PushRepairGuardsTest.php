<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Tests\Support\Push\ScriptedPushManager;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * The re-arm control is guarded on both axes it can be attacked on.
 *
 * The repair itself is not new — AccountPushController::repair() predates this
 * work and the health card already pointed at it, which is why no second route
 * was added. What is new is that two health verdicts now reach it instead of
 * one, so it is worth pinning down that neither a missing token nor somebody
 * else's account id gets through.
 *
 * Re-registering is idempotent and safe by design, but "safe" is about the
 * effect on the owner, not about who may trigger it: an unguarded endpoint
 * would let any page a user visits re-register their push, and would let any
 * logged-in user act on an account that is not theirs.
 */
final class PushRepairGuardsTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testARepairWithoutATokenIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'guard-notoken' . ScriptedPushManager::MARKER . 'joder.dev');

        $client->request('POST', '/settings/accounts/' . $account->id . '/push/repair');

        self::assertResponseStatusCodeSame(403);
    }

    public function testARepairWithAForgedTokenIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'guard-forged' . ScriptedPushManager::MARKER . 'joder.dev');

        $client->request('POST', '/settings/accounts/' . $account->id . '/push/repair', [
            '_token' => 'not-the-right-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Ownership, checked before anything else happens. A valid token minted for
     * somebody else's account id must not be enough — the token proves the
     * request came from this session, not that this session may act on that row.
     */
    public function testARepairOnSomebodyElsesAccountIsRefused(): void
    {
        $client = static::createClient();
        $owner  = $this->boot($client);

        $stranger        = new User();
        $stranger->email = 'stranger-' . bin2hex(random_bytes(4)) . '@joder.dev';
        $stranger->password  = 'not-a-real-hash';
        $stranger->nameFirst = 'Not';
        $stranger->nameLast  = 'Yours';
        $this->em->persist($stranger);
        $this->em->flush();

        $theirs = $this->account($stranger, 'theirs' . ScriptedPushManager::MARKER . 'joder.dev');

        // Logged in as the owner, posting at the stranger's account, with a
        // token that is genuinely valid for that id.
        $client->loginUser($owner);

        $client->request('POST', '/settings/accounts/' . $theirs->id . '/push/repair', [
            '_token' => $this->token($client, 'account_push_' . $theirs->id),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The happy path from the HEALTH page, which submits with no turbo-frame.
     *
     * It redirects and says what happened. Before, it answered with a
     * turbo-frame fragment for a frame the health page does not have, so the
     * page came back looking identical and the press reported nothing at all —
     * indistinguishable from a repair that did nothing.
     */
    public function testARepairFromTheHealthPageRedirectsAndReports(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'guard-ok' . ScriptedPushManager::MARKER . 'joder.dev');

        $account->pushEnabled      = true;
        $account->gmailWatchExpiry = new DateTimeImmutable('-1 day');
        $this->em->flush();

        $client->request('POST', '/settings/accounts/' . $account->id . '/push/repair', [
            '_token' => $this->token($client, 'account_push_' . $account->id),
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString(
            'section=health',
            (string) $client->getResponse()->headers->get('Location'),
            'back to the page the button was pressed on',
        );

        // And it left a sentence behind rather than a silent redirect.
        //
        // getFlashBag() is on FlashBagAwareSessionInterface, not on the base
        // SessionInterface the request is typed with, so the narrowing is an
        // assertion rather than a cast — if it ever stops holding, this fails
        // loudly instead of fataling.
        $session = $client->getRequest()->getSession();

        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertNotSame(
            [],
            $session->getFlashBag()->peekAll(),
            'the press said something',
        );
    }

    /**
     * The ACCOUNTS pane submits the same form from inside
     * `<turbo-frame id="account-push-N">` and needs the frame back, not a
     * redirect. Both callers, one route — so both shapes are pinned.
     */
    public function testARepairFromTheAccountsFrameGetsTheFrameBack(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'guard-frame' . ScriptedPushManager::MARKER . 'joder.dev');

        $account->pushEnabled      = true;
        $account->gmailWatchExpiry = new DateTimeImmutable('-1 day');
        $this->em->flush();

        $client->request(
            'POST',
            '/settings/accounts/' . $account->id . '/push/repair',
            ['_token' => $this->token($client, 'account_push_' . $account->id)],
            [],
            ['HTTP_TURBO_FRAME' => 'account-push-' . $account->id],
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'turbo-frame',
            (string) $client->getResponse()->getContent(),
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * A token minted against the browser's own session — see
     * CalendarDeletionRevokesPushTest::token() for why the GET is required.
     */
    private function token(KernelBrowser $client, string $id): string
    {
        $client->request('GET', '/settings?section=accounts');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return (string) static::getContainer()
                ->get('security.csrf.token_manager')
                ->getToken($id)
                ->getValue();
        } finally {
            $stack->pop();
        }
    }

    private function boot(KernelBrowser $client): User
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->disableReboot();

        $this->connection->beginTransaction();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email): Account
    {
        $account = new Account();

        $account->usr               = $user;
        $account->name              = $email;
        $account->username          = $email;
        $account->email             = $email;
        $account->authType          = AuthType::OAuth2->value;
        $account->oauthProvider     = MailProvider::Google->value;
        $account->oauthAccessToken  = 'access-token';
        $account->oauthRefreshToken = 'refresh-token';
        $account->isActive          = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
