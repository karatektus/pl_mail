<?php

declare(strict_types=1);

namespace App\Tests\Controller\Insight;

use App\Domain\Enum\Insight\InsightKind;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Insight\MailInsight;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The radar: mail insights on the Happening Soon panel, and the strip above a
 * conversation.
 *
 * Pinned as REQUESTS rather than against the repository, for the reason
 * HappeningSoonPanelTest gives: what matters is the markup a user's browser
 * receives — a card per insight, a dismiss that writes dismissedAt and only
 * dismissedAt, and a strip on the mail itself. Everything is selected by the
 * data attributes the templates stamp (`data-insight-card`,
 * `data-insight-kind`, `data-insight-strip`), never by translated strings: the
 * catalogues belong to another workstream, and a test that greps for "Track"
 * would fail in German for reasons no assertion states.
 *
 * Fixtures follow HappeningSoonPanelTest exactly — a fresh user inside a
 * transaction that is never committed — rather than borrowing the seeded
 * e2e-admin, whose mailbox this suite would otherwise have to leave exactly as
 * it found it.
 */
final class RadarPanelTest extends WebTestCase
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

    public function testTheRadarDrawsACardPerInsight(): void
    {
        $client = $this->signIn();

        $this->parcel();
        $this->pullRequest();

        $crawler = $client->request('GET', '/calendar/soon');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-insight-card]'));

        // The dated card: kind, status chip and the tracking link with the
        // attributes that make it safe to open.
        $parcel = $crawler->filter('[data-insight-card][data-insight-kind="parcel"]');
        self::assertCount(1, $parcel);
        self::assertCount(1, $parcel->filter('[data-insight-status="in_transit"]'));

        $track = $parcel->filter('a[data-insight-track]');
        self::assertCount(1, $track);
        self::assertSame('https://tracking.example.test/PKG-1', $track->attr('href'));
        self::assertSame('_blank', $track->attr('target'));
        self::assertStringContainsString('noopener', (string) $track->attr('rel'));

        // The undated card under its own section: repo, number, action and the
        // external link.
        $github = $crawler->filter('[data-insight-card][data-insight-kind="github-pr"]');
        self::assertCount(1, $github);
        self::assertCount(1, $github->filter('[data-insight-action="review_requested"]'));
        self::assertStringContainsString('plmail/pl_mail', $github->text());
        self::assertStringContainsString('#42', $github->text());
        self::assertSame(
            'https://github.example.test/plmail/pl_mail/pull/42',
            $github->filter('a[data-insight-open-url]')->attr('href'),
        );

        // Dated and undated live under separate subheadings.
        self::assertCount(2, $crawler->filter('[data-insight-section]'));
    }

    /**
     * A parcel with no ETA is still a parcel.
     *
     * Reported as "from your repos has dhl links". The undated section was
     * written when GitHub was the only thing without a date, so its card knew
     * about repos, numbers, actions and one external link it labelled "GitHub"
     * — and the section calling itself "From your repos" was true by accident
     * rather than by construction.
     *
     * It stopped being true the moment anything else failed to state a date. A
     * courier mail that never says when tells you about a parcel just the same,
     * and it landed in that list stripped of everything the dated card would
     * have given it: no status, no tracking link, no way back to the mail, and
     * a heading claiming it came from a repository.
     */
    public function testAnUndatedParcelIsDescribedAsAParcelAndNotAsARepo(): void
    {
        $client = $this->signIn();

        $this->insight(
            $this->account,
            InsightKind::Parcel,
            'DHL parcel from Zalando',
            null,
            [
                'status'      => 'in_transit',
                'trackingUrl' => 'https://tracking.example.test/PKG-2',
            ],
            $this->thread('Your parcel is on its way'),
        );

        $crawler = $client->request('GET', '/calendar/soon');

        $card = $crawler->filter('[data-insight-card][data-insight-kind="parcel"]');

        self::assertCount(1, $card);

        // Says what it is. With no date to lead with, the only thing naming this
        // for a sighted reader was the colour of its tile.
        self::assertStringContainsString(
            'Parcel',
            $card->text(),
            'an undated card does not say what kind of thing it is',
        );

        self::assertCount(1, $card->filter('[data-insight-status="in_transit"]'), 'the status went missing');
        self::assertCount(1, $card->filter('a[data-insight-track]'), 'the tracking link went missing');

        self::assertCount(
            0,
            $card->filter('.fa-github'),
            'a courier is wearing GitHub\'s mark',
        );
    }

    /**
     * Every card offers the mail it was read from, dated or not.
     *
     * An insight is a claim ABOUT a mail, so the mail is the thing that settles
     * it — and the undated cards were the ones with no way to get there, which
     * is precisely where a reader is most likely to want it, there being no
     * date to explain what they are looking at.
     */
    public function testEveryCardLinksToTheMailItWasReadFrom(): void
    {
        $client = $this->signIn();

        $this->parcel($this->thread('Dated parcel mail'));

        $this->insight(
            $this->account,
            InsightKind::GithubPullRequest,
            'Make the radar sweep',
            null,
            ['repo' => 'plmail/pl_mail', 'number' => 42],
            $this->thread('Undated pull request mail'),
        );

        $crawler = $client->request('GET', '/calendar/soon');

        $cards = $crawler->filter('[data-insight-card]');

        self::assertCount(2, $cards);

        self::assertCount(
            2,
            $crawler->filter('[data-insight-card] a[data-insight-open-mail]'),
            'a card with a thread behind it does not offer the mail',
        );
    }

    /** An empty radar adds nothing — no section, no heading, no empty state of its own. */
    public function testAnEmptyRadarRendersNothingExtra(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/calendar/soon');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-insight-card]'));
        self::assertCount(0, $crawler->filter('[data-insight-section]'));
    }

    public function testDismissWithATokenSetsDismissedAtAndTheCardIsGone(): void
    {
        $client  = $this->signIn();
        $insight = $this->parcel();

        $client->request('POST', "/insights/{$insight->id}/dismiss", [
            '_token' => $this->token($client),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['ok' => true],
            json_decode((string) $client->getResponse()->getContent(), true),
        );

        self::assertNotNull($this->reread($insight)->dismissedAt, 'the dismissal was not written');

        $crawler = $client->request('GET', '/calendar/soon');
        self::assertCount(0, $crawler->filter('[data-insight-card]'), 'a dismissed card came back');
    }

    public function testATokenlessDismissWritesNothing(): void
    {
        $client  = $this->signIn();
        $insight = $this->parcel();

        $client->request('POST', "/insights/{$insight->id}/dismiss");

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNull($this->reread($insight)->dismissedAt, 'a tokenless POST dismissed the card');
    }

    /** A valid token proves the page, not the ownership — a stranger's insight stays. */
    public function testAForeignInsightCannotBeDismissed(): void
    {
        $client = $this->signIn();

        // The signed-in user needs a card of their own so the panel renders
        // the radar — the token is minted there.
        $this->parcel();

        $stranger = $this->seedUser('radar-stranger-');
        $mailbox  = $this->seedAccount($stranger);
        $foreign  = $this->insight($mailbox, InsightKind::Parcel, 'Not your parcel', $this->now->modify('+3 days'));

        $client->request('POST', "/insights/{$foreign->id}/dismiss", [
            '_token' => $this->token($client),
        ]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNull($this->reread($foreign)->dismissedAt, "a stranger's POST dismissed the card");
    }

    public function testTheThreadViewShowsTheInsightStrip(): void
    {
        $client = $this->signIn();

        $thread = $this->thread('Your order has shipped');
        $this->parcel($thread);

        $crawler = $client->request('GET', "/mail/thread/{$thread->id}");

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-insight-strip]'));

        $card = $crawler->filter('[data-insight-strip] [data-insight-card][data-insight-kind="parcel"]');
        self::assertCount(1, $card);
        self::assertCount(1, $card->filter('a[data-insight-track]'));
    }

    /** And a thread nobody extracted anything from carries no strip at all. */
    public function testAThreadWithoutInsightsCarriesNoStrip(): void
    {
        $client = $this->signIn();

        $thread = $this->thread('Just a mail');

        $crawler = $client->request('GET', "/mail/thread/{$thread->id}");

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-insight-strip]'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function parcel(?MessageThread $thread = null): MailInsight
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
            $thread,
        );
    }

    private function pullRequest(): MailInsight
    {
        return $this->insight(
            $this->account,
            InsightKind::GithubPullRequest,
            'Make the radar sweep',
            null,
            [
                'repo'   => 'plmail/pl_mail',
                'number' => 42,
                'action' => 'review_requested',
                'url'    => 'https://github.example.test/plmail/pl_mail/pull/42',
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    private function insight(
        Account $account,
        InsightKind $kind,
        string $title,
        ?DateTimeImmutable $happensAt,
        array $payload = [],
        ?MessageThread $thread = null,
    ): MailInsight {
        $insight            = new MailInsight();
        $insight->account   = $account;
        $insight->kind      = $kind;
        $insight->title     = $title;
        $insight->payload   = $payload;
        $insight->happensAt = $happensAt;
        $insight->extractor = 'radar-test';
        $insight->dedupeKey = uniqid('radar-', true);
        $insight->thread    = $thread;

        $this->em->persist($insight);
        $this->em->flush();

        return $insight;
    }

    /** The shape SeedsMarkerFixtures::thread() seeds, minus the label the radar does not need. */
    private function thread(string $subject): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = $this->now;
        $thread->category          = MessageCategory::Primary;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->messageId      = uniqid('radar-', true) . '@example.test';
        $message->subject        = $subject;
        $message->fromAddress    = 'shop@example.test';
        $message->receivedAt     = $this->now->modify('-1 hour');
        $message->sentAt         = $message->receivedAt;
        $message->seenAt         = $message->receivedAt;
        $message->flags          = [];
        $message->hasAttachments = false;
        $thread->addMessage($message);
        $this->em->persist($message);

        $this->em->flush();

        return $thread;
    }

    /**
     * The per-action token, read off the rendered radar — which is also the
     * only place the real button gets it from (AppearancePaneStateTest reads
     * its token the same way).
     */
    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/calendar/soon');

        return (string) $crawler
            ->filter('[data-controller="insight--radar"]')
            ->attr('data-insight--radar-token-value');
    }

    /** The row as the database now has it, not as the last request left it. */
    private function reread(MailInsight $insight): MailInsight
    {
        $id = $insight->id;

        $this->em->clear();

        $found = $this->em->find(MailInsight::class, $id);

        self::assertInstanceOf(MailInsight::class, $found);

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

        $this->user    = $this->seedUser('radar-panel-');
        $this->account = $this->seedAccount($this->user);

        $client->loginUser($this->user);

        return $client;
    }

    private function seedUser(string $prefix): User
    {
        $user            = new User();
        $user->email     = $prefix . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Radar';
        $user->nameLast  = 'Panel';
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
        $account->name           = 'Radar fixture';
        $account->email          = uniqid('radar-', true) . '@example.test';
        $account->username       = uniqid('radar-', true);
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
