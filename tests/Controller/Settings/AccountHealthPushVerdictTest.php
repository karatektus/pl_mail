<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Is this lapsed, or is it alive and silent?" — answered on the page.
 *
 * WHY THIS FILE EXISTS
 * ────────────────────
 * A live install went twelve hours without a single Gmail push while its owner
 * watched mail keep arriving on the polling fallback. The app said nothing for
 * any of it, and when they went looking there was no way to tell, from the UI,
 * which of two completely different things had happened:
 *
 *   - the watch had EXPIRED, because the daily renewal command stopped running
 *     (a scheduler that is down does not fail — it simply does not fire, which
 *     logs nothing at all), or
 *   - the watch was ALIVE and nothing was coming through it, which for Gmail
 *     means the Pub/Sub leg between Google and the endpoint.
 *
 * The two send somebody to two different places to look, so the page has to say
 * which it is. Every test here is about that distinction holding.
 *
 * THE ONE THAT MUST NOT REGRESS
 * ─────────────────────────────
 * testAQuietMailboxWithALiveWatchRaisesNothing. The old check reported push as
 * broken after 36 hours of silence, and on a mailbox that simply had no mail
 * that was a false alarm — which is the entire reason the threshold had been
 * made so generous in the first place. Getting the alarm right is worth
 * nothing if it costs the ability to stay quiet.
 */
final class AccountHealthPushVerdictTest extends WebTestCase
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

    /**
     * The watch ran out. A fact, not a threshold — and true at any hour.
     */
    public function testALapsedWatchIsReportedAsLapsed(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->gmailAccount($user, 'lapsed@joder.dev');

        $account->pushEnabled      = true;
        $account->gmailWatchExpiry = new DateTimeImmutable('-1 day');
        $this->em->flush();

        $card = $this->pushCard($client, $account);

        self::assertSame(1, $card->count(), 'a lapsed watch is surfaced');
        self::assertSame('push_lapsed', $card->attr('data-health-kind'));
    }

    /**
     * The watch is alive and unexpired, and the mailbox demonstrably changed
     * long after the last push announced anything. That is a change push
     * missed, which is a different fault with a different place to look.
     */
    public function testALiveButSilentWatchIsReportedDifferently(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->gmailAccount($user, 'silent@joder.dev');

        $account->pushEnabled = true;
        // Unexpired, so the lapsed branch cannot be what fires.
        $account->gmailWatchExpiry = new DateTimeImmutable('+5 days');
        // Delivered once, hours ago...
        $account->gmailLastPushAt = new DateTimeImmutable('-6 hours');
        // ...and the mailbox has moved since, without a push to announce it.
        $account->gmailHistoryAdvancedAt = new DateTimeImmutable('-1 hour');
        $this->em->flush();

        $card = $this->pushCard($client, $account);

        self::assertSame(1, $card->count(), 'a silent-but-live watch is surfaced');
        self::assertSame(
            'push_degraded',
            $card->attr('data-health-kind'),
            'and it is NOT reported as the lapsed case',
        );
    }

    /**
     * The false-alarm case, and the whole reason the old threshold was 36 hours.
     *
     * Nothing has been delivered for a week, and that is correct: nothing has
     * happened in the mailbox for a week either. A push that delivered nothing
     * because there was nothing to deliver is not broken, and saying otherwise
     * is how the indicator stops being believed.
     */
    public function testAQuietMailboxWithALiveWatchRaisesNothing(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->gmailAccount($user, 'quiet@joder.dev');

        $account->pushEnabled      = true;
        $account->gmailWatchExpiry = new DateTimeImmutable('+5 days');
        // Silent for a week — far past every threshold the old check used.
        $account->gmailLastPushAt = new DateTimeImmutable('-7 days');
        // And the mailbox has not changed in all that time, so there is no
        // evidence of anything push failed to announce.
        $account->gmailHistoryAdvancedAt = new DateTimeImmutable('-8 days');
        $this->em->flush();

        self::assertSame(
            0,
            $this->pushCard($client, $account)->count(),
            'a quiet mailbox is not a broken one, however long it stays quiet',
        );
    }

    /**
     * A push that arrived alongside the change it announced is working, even
     * though the history moved a little after the push landed — the poll that
     * recorded it runs on a quarter-hour sweep. See PUSH_LAG_GRACE.
     */
    public function testAPushThatKeptUpWithTheMailboxIsHealthy(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->gmailAccount($user, 'keeping-up@joder.dev');

        $account->pushEnabled            = true;
        $account->gmailWatchExpiry       = new DateTimeImmutable('+5 days');
        $account->gmailLastPushAt        = new DateTimeImmutable('-10 minutes');
        $account->gmailHistoryAdvancedAt = new DateTimeImmutable('-9 minutes');
        $this->em->flush();

        self::assertSame(0, $this->pushCard($client, $account)->count());
    }

    /**
     * Both failures light the topbar indicator now.
     *
     * This is the assertion that answers the original complaint — the user
     * noticed their push was dead before the app did. Neither verdict is an
     * inference any more, so neither can cry wolf, so both have earned the one
     * interruption this feature has.
     */
    public function testABrokenPushLightsTheIndicator(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->gmailAccount($user, 'loud@joder.dev');

        $account->pushEnabled      = true;
        $account->gmailWatchExpiry = new DateTimeImmutable('-1 day');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertSame('warning', $this->pushCard($client, $account)->attr('data-health-severity'));
        self::assertGreaterThan(
            0,
            $crawler->filter('nav a[href*="section=health"] span.rounded-full')->count(),
            'a push that is certainly broken is worth interrupting somebody for',
        );
    }

    /**
     * The dates behind the verdict, on the card.
     *
     * The complaint was not only that the app said nothing — it was that when
     * it did say something there was no way to check the reasoning without a
     * database client. Expiry, last delivery and last renewal run are the three
     * facts somebody diagnoses from.
     */
    public function testTheCardShowsTheFactsBehindTheVerdict(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->gmailAccount($user, 'facts@joder.dev');

        $account->pushEnabled      = true;
        $account->gmailWatchExpiry = new DateTimeImmutable('-1 day');
        $account->gmailLastPushAt  = new DateTimeImmutable('-3 days');
        $this->em->flush();

        $card  = $this->pushCard($client, $account);
        $facts = $card->filter('[data-health-facts]');

        self::assertSame(1, $facts->count(), 'the evidence is on the card, not behind a disclosure');

        foreach ([
            'settings.health.fact.push_expires',
            'settings.health.fact.push_last_delivered',
            'settings.health.fact.push_last_renewal',
        ] as $label) {
            self::assertSame(
                1,
                $card->filter('[data-health-fact="' . $label . '"]')->count(),
                $label . ' is shown',
            );
        }

        // The expiry that proves the verdict, rendered as a real timestamp
        // somebody can compare against a scheduler log — not a vague "2 days
        // ago", which is exactly what cannot be checked against anything.
        //
        // Matched by shape rather than by value: the page renders in the user's
        // display zone and this process runs in whatever the container's
        // default is, so asserting the exact string would be asserting that the
        // two happen to agree.
        self::assertMatchesRegularExpression(
            '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/',
            $card->filter('[data-health-fact="settings.health.fact.push_expires"]')->text(),
        );

        // Renewal has never been recorded in this test's transaction, and the
        // card says so rather than leaving a blank that reads as a bug.
        self::assertNotSame(
            '',
            trim($card->filter('[data-health-fact="settings.health.fact.push_last_renewal"]')->text()),
        );
    }

    /**
     * The re-arm repair is offered for both verdicts, and it is the control
     * that already existed rather than a second one — re-registering is
     * idempotent and fixes either.
     */
    public function testBothVerdictsOfferTheReArmRepair(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $lapsed                    = $this->gmailAccount($user, 'rearm-lapsed@joder.dev');
        $lapsed->pushEnabled       = true;
        $lapsed->gmailWatchExpiry  = new DateTimeImmutable('-1 day');

        $silent                          = $this->gmailAccount($user, 'rearm-silent@joder.dev');
        $silent->pushEnabled             = true;
        $silent->gmailWatchExpiry        = new DateTimeImmutable('+5 days');
        $silent->gmailLastPushAt         = new DateTimeImmutable('-6 hours');
        $silent->gmailHistoryAdvancedAt  = new DateTimeImmutable('-1 hour');

        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        foreach ([$lapsed, $silent] as $account) {
            $card = $crawler->filter('[data-health-issue="push-' . $account->id . '"]');

            self::assertSame(
                1,
                $card->filter('form[action*="/push/repair"]')->count(),
                'the existing repair, offered for ' . $account->email,
            );
            // And it carries a token: this is a state-changing POST.
            self::assertSame(1, $card->filter('form[action*="/push/repair"] input[name="_token"]')->count());
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return \Symfony\Component\DomCrawler\Crawler */
    private function pushCard(KernelBrowser $client, Account $account)
    {
        return $client->request('GET', '/settings?section=health')
            ->filter('[data-health-issue="push-' . $account->id . '"]');
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

        $this->em->createQuery('DELETE FROM ' . Calendar::class . ' c WHERE c.usr = :usr')
            ->setParameter('usr', $user)->execute();
        $this->em->createQuery('DELETE FROM ' . Account::class . ' a WHERE a.usr = :usr')
            ->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function gmailAccount(User $user, string $email): Account
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
