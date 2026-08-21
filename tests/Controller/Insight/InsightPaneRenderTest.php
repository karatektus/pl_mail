<?php

declare(strict_types=1);

namespace App\Tests\Controller\Insight;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Insight\MailInsight;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Insight\InsightPane;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The strip above the mail list, as MARKUP: what `insight/_pane.html.twig`
 * draws for a set of rows, what it draws for none, and the frame the mailbox
 * layout leaves for it.
 *
 * Pinned as requests rather than as a Twig render, for the reason
 * RadarPanelTest gives: what matters is what a browser receives. Everything is
 * selected by the data attributes the template stamps — `data-insight-pane`,
 * `data-insight-card`, `data-insight-kind` — and never by a translated string,
 * because the catalogues belong to another workstream and a test that grepped
 * for "Happening soon" would fail in German for reasons no assertion states.
 *
 * The claim worth the most here is the SECOND one. An empty strip must render
 * nothing at all — no heading, no bordered band, no empty state — because that
 * is the case InsightPane's class doc trades on to justify a band above the
 * inbox at all, and it is the case that happens to most people on most days.
 * The frame element itself still comes back, and that is asserted too: without
 * it Turbo has nothing to swap the lazy load into and the frame never finishes
 * loading.
 *
 * The mailbox page is checked for the FRAME and against the CARDS: lazy means
 * the strip is not in the page's own HTML, and a frame that had quietly become
 * eager would still pass an assertion that only looked for the frame.
 *
 * Fixtures follow RadarPanelTest exactly — a fresh user inside a transaction
 * that is never committed — rather than borrowing the seeded e2e user, whose
 * mailbox this suite would then have to leave as it found it.
 */
final class InsightPaneRenderTest extends WebTestCase
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

    public function testTheStripDrawsARowPerInsight(): void
    {
        $client = $this->signIn();

        $this->parcel();
        $this->ticket();

        $crawler = $client->request('GET', '/insights/pane');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#insight_pane'));
        self::assertCount(1, $crawler->filter('[data-insight-pane]'));
        self::assertCount(2, $crawler->filter('[data-insight-card]'));

        // The dated row: kind, status tone and the tracking link with the
        // attributes that make it safe to open.
        $parcel = $crawler->filter('[data-insight-card][data-insight-kind="parcel"]');
        self::assertCount(1, $parcel);
        self::assertCount(1, $parcel->filter('[data-insight-status="in_transit"]'));

        $track = $parcel->filter('a[data-insight-track]');
        self::assertCount(1, $track);
        self::assertSame('https://tracking.example.test/PKG-1', $track->attr('href'));
        self::assertSame('_blank', $track->attr('target'));
        self::assertStringContainsString('noopener', (string) $track->attr('rel'));

        // A row with no tracking page falls through to its `url` instead, and
        // gets the quieter of the two buttons — never both.
        $ticket = $crawler->filter('[data-insight-card][data-insight-kind="ticket"]');
        self::assertCount(1, $ticket);
        self::assertCount(0, $ticket->filter('a[data-insight-track]'));
        self::assertSame(
            'https://tickets.example.test/T-9',
            $ticket->filter('a[data-insight-open-url]')->attr('href'),
        );

        // Every row carries its own dismissal, pointed at the per-insight
        // endpoint — the strip's own ✕ is a different scope and a different
        // route, and both are on the page exactly once each.
        self::assertCount(2, $crawler->filter('[data-insight-card] [data-insight-dismiss]'));
        self::assertCount(1, $crawler->filter('[data-insight-pane-dismiss]'));
    }

    /**
     * Nothing to say, nothing drawn. The frame comes back so the lazy load can
     * finish; everything inside it is absent.
     */
    public function testAnEmptyStripRendersNothingAtAll(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/insights/pane');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#insight_pane'), 'the frame must come back even when empty');
        self::assertCount(0, $crawler->filter('[data-insight-pane]'), 'an empty strip drew a band');
        self::assertCount(0, $crawler->filter('[data-insight-card]'));
        self::assertCount(0, $crawler->filter('[data-insight-pane-dismiss]'));
        self::assertSame(
            '',
            trim($crawler->filter('turbo-frame#insight_pane')->text()),
            'an empty strip printed a heading or an empty state',
        );
    }

    /** More than fits is not the template's problem, but nor may it draw more than it was given. */
    public function testTheStripDrawsNoMoreRowsThanItIsHanded(): void
    {
        $client = $this->signIn();

        for ($i = 0; $i < InsightPane::MAX_ROWS + 2; ++$i) {
            $this->insight(
                $this->account,
                InsightKind::Parcel,
                'Parcel ' . $i,
                $this->now->modify('+' . ($i + 1) . ' days'),
            );
        }

        $crawler = $client->request('GET', '/insights/pane');

        self::assertResponseIsSuccessful();
        self::assertCount(InsightPane::MAX_ROWS, $crawler->filter('[data-insight-card]'));
    }

    /**
     * The mailbox still renders, and it renders the frame rather than the
     * strip: lazy is the whole answer to "a mailbox load must not pay for this
     * query", so an eager frame here would be the feature's cost coming back.
     */
    public function testTheMailboxCarriesTheLazyFrameAndNotTheStrip(): void
    {
        $client = $this->signIn();

        $this->parcel();

        $crawler = $client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        $frame = $crawler->filter('turbo-frame#insight_pane');

        self::assertCount(1, $frame);
        self::assertSame('/insights/pane', $frame->attr('src'));
        self::assertSame('lazy', $frame->attr('loading'));
        self::assertCount(0, $crawler->filter('[data-insight-card]'), 'the mailbox rendered the strip inline');

        // Inside the list pane, so the band spans the list's width and narrows
        // when the reading pane opens — the placement the layout comments.
        self::assertCount(1, $crawler->filter('#message-list > turbo-frame#insight_pane'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function parcel(): MailInsight
    {
        return $this->insight(
            $this->account,
            InsightKind::Parcel,
            'DHL parcel from Zalando',
            $this->now->modify('+2 days')->setTime(9, 0),
            [
                'status'      => 'in_transit',
                'trackingUrl' => 'https://tracking.example.test/PKG-1',
            ],
        );
    }

    /**
     * The second shape of row: dated like the parcel, but with a `url` where
     * the parcel has a `trackingUrl`.
     *
     * Dated on purpose, and not a pull request. InsightPane hands the strip
     * `upcomingForUser`, which windows on happensAt and therefore never
     * carries the undated GitHub family at all — those belong to the radar
     * panel, and a fixture that put one here would be asserting against a
     * combination the strip cannot be given.
     */
    private function ticket(): MailInsight
    {
        return $this->insight(
            $this->account,
            InsightKind::Ticket,
            'Two seats, Thursday',
            $this->now->modify('+4 days')->setTime(19, 30),
            ['url' => 'https://tickets.example.test/T-9'],
        );
    }

    /** @param array<string, mixed> $payload */
    private function insight(
        Account $account,
        InsightKind $kind,
        string $title,
        ?DateTimeImmutable $happensAt,
        array $payload = [],
    ): MailInsight {
        $insight            = new MailInsight();
        $insight->account   = $account;
        $insight->kind      = $kind;
        $insight->title     = $title;
        $insight->payload   = $payload;
        $insight->happensAt = $happensAt;
        $insight->extractor = 'pane-test';
        $insight->dedupeKey = uniqid('pane-', true);

        $this->em->persist($insight);
        $this->em->flush();

        return $insight;
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
        $account->name           = 'Insight pane fixture';
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
