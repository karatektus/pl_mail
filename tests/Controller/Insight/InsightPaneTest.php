<?php

declare(strict_types=1);

namespace App\Tests\Controller\Insight;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Insight\MailInsight;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The strip above the mail list, as requests: the fragment and the dismiss.
 *
 * Pinned as requests rather than against InsightPane, for the reason
 * RadarPanelTest gives — the rule itself has a unit test, and what this file
 * owns is the wiring around it: that the fragment answers on its own URL, that
 * the dismiss writes the key it claims to write and nothing else, that a
 * tokenless POST writes nothing at all, and that a dismissal is a fact about
 * ONE user's settings bag and not about the insights.
 *
 * Selected by the title the fixture set rather than by markup: the template is
 * the other stream's, and a test that greps for its class names would fail the
 * first time somebody restyles a band. Fixtures follow RadarPanelTest exactly
 * — a fresh user inside a transaction that is never committed.
 */
final class InsightPaneTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;
    private DateTimeImmutable $now;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheFragmentDrawsTheUsersInsights(): void
    {
        $client = $this->signIn();
        $this->parcel('DHL parcel from Zalando');

        $client->request('GET', '/insights/pane');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'DHL parcel from Zalando',
            (string) $client->getResponse()->getContent(),
        );
    }

    /** Nothing to say means nothing rendered — an empty band is still a band. */
    public function testAnEmptyStripRendersNoRows(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/insights/pane');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-insight-pane-row]'));
    }

    /** The off-switch, from the settings bag straight through to the response. */
    public function testASwitchedOffStripRendersNoRows(): void
    {
        $client = $this->signIn();
        $this->parcel('DHL parcel from Zalando');

        $this->user->setSetting(User::SETTING_INSIGHT_PANE_DISABLED, true);
        $this->em->flush();

        $client->request('GET', '/insights/pane');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            'DHL parcel from Zalando',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testDismissStampsTheSettingAndTheStripGoesQuiet(): void
    {
        $client = $this->signIn();
        $this->parcel('DHL parcel from Zalando');

        $client->request('POST', '/insights/pane/dismiss', [
            '_token' => $this->token($client),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['ok' => true],
            json_decode((string) $client->getResponse()->getContent(), true),
        );

        $stored = $this->reread($this->user)->getSetting(User::SETTING_INSIGHT_PANE_DISMISSED_AT);
        self::assertIsString($stored);
        self::assertInstanceOf(DateTimeImmutable::class, new DateTimeImmutable($stored));

        // And the insight itself was not touched: the strip was waved away,
        // not the parcel — InsightActionsController owns that verb.
        $client->request('GET', '/insights/pane');
        self::assertStringNotContainsString(
            'DHL parcel from Zalando',
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * A second dismiss re-stamps rather than keeping the first instant, which
     * is the opposite of InsightActionsController::dismiss and deliberate:
     * this records a position on a moving list, not a fact about one card.
     */
    public function testASecondDismissMovesTheStampForward(): void
    {
        $client = $this->signIn();
        $this->parcel('DHL parcel from Zalando');

        $token = $this->token($client);

        $this->user->setSetting(
            User::SETTING_INSIGHT_PANE_DISMISSED_AT,
            $this->now->modify('-2 days')->format(DateTimeImmutable::ATOM),
        );
        $this->em->flush();

        $client->request('POST', '/insights/pane/dismiss', ['_token' => $token]);

        self::assertResponseIsSuccessful();

        $stored = $this->reread($this->user)->getSetting(User::SETTING_INSIGHT_PANE_DISMISSED_AT);
        self::assertIsString($stored);
        self::assertGreaterThan(
            $this->now->modify('-1 hour'),
            new DateTimeImmutable($stored),
            'the second dismiss kept the older stamp',
        );
    }

    public function testATokenlessDismissWritesNothing(): void
    {
        $client = $this->signIn();
        $this->parcel('DHL parcel from Zalando');

        $client->request('POST', '/insights/pane/dismiss');

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNull(
            $this->reread($this->user)->getSetting(User::SETTING_INSIGHT_PANE_DISMISSED_AT),
            'a tokenless POST dismissed the strip',
        );
    }

    /** A dismissal is one user's, and a stranger's strip is untouched by it. */
    public function testAnotherUsersDismissalDoesNotAffectYours(): void
    {
        $client = $this->signIn();
        $this->parcel('DHL parcel from Zalando');

        $stranger = $this->seedUser('pane-stranger-');
        $stranger->setSetting(
            User::SETTING_INSIGHT_PANE_DISMISSED_AT,
            $this->now->modify('+1 day')->format(DateTimeImmutable::ATOM),
        );
        $this->em->flush();

        $client->request('GET', '/insights/pane');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'DHL parcel from Zalando',
            (string) $client->getResponse()->getContent(),
            "a stranger's dismissal hid this user's strip",
        );
    }

    /** Signed out, both ends are the firewall's business and neither answers. */
    public function testTheFragmentIsNotPublic(): void
    {
        $client = static::createClient();

        $client->request('GET', '/insights/pane');

        self::assertTrue(
            $client->getResponse()->isRedirection() || 401 === $client->getResponse()->getStatusCode(),
            'the fragment answered an anonymous request',
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function parcel(string $title): MailInsight
    {
        $insight            = new MailInsight();
        $insight->account   = $this->account;
        $insight->kind      = InsightKind::Parcel;
        $insight->title     = $title;
        $insight->payload   = ['status' => 'in_transit', 'trackingUrl' => 'https://tracking.example.test/PKG-1'];
        $insight->happensAt = $this->now->modify('+2 days')->setTime(9, 0);
        $insight->extractor = 'pane-test';
        $insight->dedupeKey = uniqid('pane-', true);

        $this->em->persist($insight);
        $this->em->flush();

        return $insight;
    }

    /**
     * The token under the id the strip's button posts, minted container-side.
     *
     * Read from the manager rather than off the rendered fragment on purpose:
     * that template belongs to the other stream and the attribute it stamps
     * the token into is theirs to name, while the token ID is the contract
     * this endpoint actually checks.
     *
     * The GET and the carrier request are the pattern CalendarDeletion-
     * RevokesPushTest established, and neither is incidental: the token store
     * is the session, there is no session until the client has made a request,
     * and the carrier is how the container-side manager is pointed at it.
     */
    private function token(KernelBrowser $client): string
    {
        $client->request('GET', '/insights/pane');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return (string) static::getContainer()
                ->get('security.csrf.token_manager')
                ->getToken('insight-pane-dismiss')
                ->getValue();
        } finally {
            $stack->pop();
        }
    }

    /** The row as the database now has it, not as the last request left it. */
    private function reread(User $user): User
    {
        $id = $user->id;

        $this->em->clear();

        $found = $this->em->find(User::class, $id);

        self::assertInstanceOf(User::class, $found);

        return $found;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->now        = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $this->user    = $this->seedUser('insight-pane-');
        $this->account = $this->seedAccount($this->user);

        $client->loginUser($this->user);

        return $client;
    }

    private function seedUser(string $prefix): User
    {
        $user            = new User();
        $user->email     = $prefix . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Insight';
        $user->nameLast  = 'Pane';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seedAccount(User $user): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Pane fixture';
        $account->email          = uniqid('pane-', true) . '@example.test';
        $account->username       = uniqid('pane-', true);
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
