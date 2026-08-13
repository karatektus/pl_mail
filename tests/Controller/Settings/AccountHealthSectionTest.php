<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The account-health section: what it says, when it says nothing, and who is
 * allowed to press the buttons.
 *
 * WHAT THIS IS FOR
 * ────────────────
 * The app already DETECTED every condition on this page and then discarded it.
 * Account::$oauthLastRefreshError was written by OAuthTokenManager and read by
 * no template and no controller; on the install this was built from it had held
 * `invalid_grant` for two days while mail silently stopped arriving, and the
 * only trace was five thousand log lines nobody had reason to open.
 *
 * THE ASSERTION THAT MATTERS MOST is not that a problem shows up — that is the
 * easy half, and a page that showed a card for everything would pass it. It is
 * testAHealthyAccountReportsNothing and its siblings: a health page that cries
 * wolf gets ignored, and once it is ignored the red card that mattered is
 * unread too. Every detection test below has a healthy counterpart.
 */
final class AccountHealthSectionTest extends WebTestCase
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

    // ── the section itself ───────────────────────────────────────────────────

    public function testTheSectionRendersAndIsReachableFromTheNav(): void
    {
        $client = static::createClient();
        $this->boot($client);

        $crawler = $client->request('GET', '/settings?section=accounts');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('nav a[href*="section=health"]')->count(),
            'the settings nav links to the health section',
        );

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('#settings-health')->count());

        // Survives SettingsController::SECTIONS — an unknown section falls back
        // to accounts, which would render no panel at all and still be a 200.
        self::assertGreaterThan(
            0,
            $crawler->filter('nav a[href*="section=health"][aria-current="page"]')->count(),
            'the nav marks health as the current page',
        );
    }

    // ── nothing wrong means nothing said ─────────────────────────────────────

    /**
     * The false-positive guard. A working OAuth account, a calendar that has
     * never synced because it mirrors nothing, and a password account all
     * count as healthy — none of them is evidence of a problem.
     */
    public function testAHealthyAccountReportsNothing(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $this->oauthAccount($user, 'working@joder.dev');
        $this->account($user, 'imap@joder.dev');

        // A calendar with no stored error. Never synced, which is normal for one
        // that has only just been subscribed to.
        $calendar               = $this->calendar($user, 'Empty but fine');
        $calendar->lastSyncedAt = null;

        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            $crawler->filter('[data-health-issue]')->count(),
            'nothing is wrong, so nothing is reported',
        );
        self::assertSame(1, $crawler->filter('[data-health-empty]')->count(), 'and it says so in words');
    }

    /**
     * The indicator is absent when there is nothing to indicate. Pinned
     * separately from the page: the badge renders from a Twig global on every
     * authenticated page, so it can be wrong while the page is right.
     */
    public function testTheIndicatorIsAbsentWhenEverythingIsWorking(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $this->oauthAccount($user, 'quiet@joder.dev');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            $crawler->filter('nav a[href*="section=health"] span.rounded-full')->count(),
            'no badge on the nav entry',
        );
    }

    // ── the dead grant ───────────────────────────────────────────────────────

    /**
     * The condition the whole feature exists for: stored, and until now never
     * read back.
     */
    public function testADeadOauthGrantIsReportedWithAReconnect(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->oauthAccount($user, 'broken@joder.dev');

        $account->oauthLastRefreshError = 'invalid_grant';
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-health-issue="account-' . $account->id . '"]');

        self::assertSame(1, $card->count(), 'the dead grant gets a card');
        self::assertSame('account_reconnect', $card->attr('data-health-kind'));
        self::assertSame('critical', $card->attr('data-health-severity'));

        // Phrased for a person. The provider's code is NOT the headline — see
        // MailProvider::calendarScopes() on why "403" is not an explanation.
        self::assertStringContainsString('broken@joder.dev', $card->text());
        self::assertStringNotContainsString('invalid_grant', $card->filter('h3')->text());

        // ...but it is still findable, one disclosure away.
        self::assertStringContainsString('invalid_grant', $card->filter('details pre')->text());

        // The repair is a link, not a form: it leaves for the provider's
        // consent screen and comes back, so it cannot be a POST from here.
        $link = $card->filter('a[href*="/settings/health/reconnect/"]');

        self::assertSame(1, $link->count(), 'there is a reconnect button');
        self::assertStringEndsWith('/reconnect/' . $account->id, (string) $link->attr('href'));

        // ...and following it hands off to the OAuth flow with the repair
        // intent attached, rather than to the "add an account" path.
        $client->request('GET', (string) $link->attr('href'));

        self::assertResponseRedirects();
        self::assertStringContainsString(
            'reconnect=' . $account->id,
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    /**
     * An OAuth account with no refresh token on file at all — the same problem
     * arriving by a different road (OAuthTokenManager::refresh() throws for it
     * before it ever reaches the provider).
     */
    public function testAnAccountWithNoRefreshTokenIsAlsoReported(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->oauthAccount($user, 'tokenless@joder.dev');

        $account->oauthRefreshToken = null;
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertSame(1, $crawler->filter('[data-health-issue="account-' . $account->id . '"]')->count());
    }

    /**
     * A password account has no grant to be dead, and must never be told to
     * reconnect one.
     */
    public function testAPasswordAccountIsNeverAskedToReconnect(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'imaponly@joder.dev');

        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertSame(0, $crawler->filter('[data-health-issue="account-' . $account->id . '"]')->count());
    }

    // ── calendars, and the difference between a cause and a symptom ──────────

    /**
     * A failing calendar on a healthy account stands on its own: it is the
     * problem, so it is critical and it gets its own retry.
     */
    public function testAFailingCalendarOnAHealthyAccountIsItsOwnProblem(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $account  = $this->oauthAccount($user, 'fine@joder.dev');
        $calendar = $this->remoteCalendar($user, $account, 'Holidays');

        $calendar->lastSyncError = 'The calendar no longer exists at the remote.';
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');
        $card    = $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"]');

        self::assertSame(1, $card->count());
        self::assertSame('calendar_sync_failing', $card->attr('data-health-kind'));
        self::assertSame('critical', $card->attr('data-health-severity'));
        self::assertNull($card->attr('data-health-caused-by'), 'nothing above it explains it');

        // And it offers a retry, because a retry could plausibly work here.
        self::assertSame(1, $card->filter('form[action*="/resync"]')->count());
    }

    /**
     * The grouping that keeps one broken sign-in from reading as four
     * emergencies. This is the exact shape of the live install: one dead grant,
     * three calendars that stopped with it.
     */
    public function testCalendarsFailingBecauseOfADeadGrantAreShownAsConsequences(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->oauthAccount($user, 'cascade@joder.dev');

        $account->oauthLastRefreshError = 'invalid_grant';

        $calendars = [];

        foreach (['Feiertage', 'Familie', 'cascade@joder.dev'] as $name) {
            $calendar                = $this->remoteCalendar($user, $account, $name);
            $calendar->lastSyncError = 'Google would not renew the sign-in for this account.';
            $calendars[]             = $calendar;
        }

        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        foreach ($calendars as $calendar) {
            $card = $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"]');

            self::assertSame(1, $card->count(), 'the calendar is still listed by name');
            self::assertSame(
                'account-' . $account->id,
                $card->attr('data-health-caused-by'),
                'and it is attributed to the sign-in that caused it',
            );
            self::assertSame(
                'warning',
                $card->attr('data-health-severity'),
                'a consequence is not a second emergency',
            );

            // No retry button: with the grant dead, "try again" is a button
            // whose entire behaviour is to fail.
            self::assertSame(0, $card->filter('form[action*="/resync"]')->count());
        }

        // The badge counts ONE thing to do, not four.
        $badge = $crawler->filter('nav a[href*="section=health"] span.rounded-full');

        self::assertSame(1, $badge->count());
        self::assertSame('1', trim($badge->text()));
    }

    /** A calendar that syncs fine is not mentioned. */
    public function testAWorkingCalendarIsNotReported(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $account  = $this->oauthAccount($user, 'cal-ok@joder.dev');
        $calendar = $this->remoteCalendar($user, $account, 'Working');

        $calendar->recordSyncSuccess();
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertSame(0, $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"]')->count());
    }

    // ── the indicator appears, and goes when the problem does ────────────────

    /**
     * The other half of the indicator requirement, and the reason there is no
     * "mark as seen" anywhere in this feature.
     *
     * LogAlertGlobal is dismissible because a log is a record of things that
     * happened. A health issue is a condition that is still true, and a dot the
     * user could clear while their mail was still not arriving would be worse
     * than no dot at all — the next time it appeared, they would already have
     * learned that clearing it is what you do. So the only thing that clears it
     * is the repair, which is what this asserts.
     */
    public function testTheIndicatorAppearsWithTheProblemAndGoesWhenItIsRepaired(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->oauthAccount($user, 'indicator@joder.dev');

        $account->oauthLastRefreshError = 'invalid_grant';
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');
        $badge   = $crawler->filter('nav a[href*="section=health"] span.rounded-full');

        self::assertSame(1, $badge->count(), 'the indicator appears');
        self::assertSame('1', trim($badge->text()));

        // Repaired the way a real reconnect repairs it — OAuthAccountLinker
        // clears exactly this field, and nothing else marks the issue "read".
        $account = $this->em->find(Account::class, $account->id);
        $account->oauthLastRefreshError = null;
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertSame(
            0,
            $crawler->filter('nav a[href*="section=health"] span.rounded-full')->count(),
            'and goes when the condition does — with nothing having been dismissed',
        );
        self::assertSame(1, $crawler->filter('[data-health-empty]')->count());
    }

    // ── ownership and CSRF ───────────────────────────────────────────────────

    /**
     * These actions touch account credentials and background work. A logged-in
     * session is not enough.
     */
    public function testResyncingACalendarWithoutATokenIsRefused(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $account  = $this->oauthAccount($user, 'csrf@joder.dev');
        $calendar = $this->remoteCalendar($user, $account, 'Guarded');

        $calendar->lastSyncError = 'something';
        $this->em->flush();

        $client->request('POST', '/settings/health/calendar/' . $calendar->id . '/resync');

        self::assertResponseStatusCodeSame(403);
    }

    public function testResyncingSomebodyElsesCalendarIsRefused(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $stranger = $this->stranger();
        $calendar = $this->calendar($stranger, 'Not yours');

        $this->em->flush();

        $client->request('POST', '/settings/health/calendar/' . $calendar->id . '/resync', [
            '_token' => 'anything',
        ]);

        // Ownership is checked BEFORE the token, so this is a refusal on the
        // grounds that matter rather than an accidental pass on a bad token.
        self::assertResponseStatusCodeSame(403);
    }

    public function testReconnectingSomebodyElsesAccountIsRefused(): void
    {
        $client = static::createClient();
        $this->boot($client);

        $stranger = $this->stranger();
        $account  = $this->oauthAccount($stranger, 'theirs@joder.dev');

        $this->em->flush();

        $client->request('GET', '/settings/health/reconnect/' . $account->id);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The reconnect entry point refuses an id that is not the caller's even at
     * the OAuth controller, which is the one that stores the repair intent in
     * the session.
     */
    public function testStartingAnOauthReconnectForSomebodyElsesAccountIsRefused(): void
    {
        $client = static::createClient();
        $this->boot($client);

        $stranger = $this->stranger();
        $account  = $this->oauthAccount($stranger, 'session@joder.dev');

        $this->em->flush();

        $client->request('GET', '/oauth/google/connect?reconnect=' . $account->id);

        self::assertResponseStatusCodeSame(403);
    }

    /** The calendar resync really does clear the backoff, not just dispatch. */
    public function testResyncingClearsTheBackoffSoTheSweepPicksItUpAgain(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $account  = $this->oauthAccount($user, 'backoff@joder.dev');
        $calendar = $this->remoteCalendar($user, $account, 'Backed off');

        $calendar->lastSyncError = 'The calendar no longer exists at the remote.';
        $calendar->recordSyncFailure('The calendar no longer exists at the remote.');
        $this->em->flush();

        self::assertTrue($calendar->isBackingOff());

        $crawler = $client->request('GET', '/settings?section=health');
        $form    = $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"] form[action*="/resync"]');

        self::assertSame(1, $form->count());

        $client->request('POST', (string) $form->attr('action'), [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects('/settings?section=health');

        $this->em->clear();
        $reloaded = $this->em->find(Calendar::class, $calendar->id);

        self::assertFalse($reloaded->isBackingOff(), 'the schedule is reset so the sweep tries again');
        self::assertNotNull($reloaded->lastSyncError, 'but it is still broken until a sync says otherwise');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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

        // Seeded fixtures would otherwise show up as health issues of their own
        // and make every count assertion below depend on them.
        $this->em->createQuery(
            'DELETE FROM ' . Calendar::class . ' c WHERE c.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->createQuery(
            'DELETE FROM ' . Account::class . ' a WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    /** Another user, so ownership can be tested against a real second owner. */
    private function stranger(): User
    {
        $user = new User();

        $user->email     = 'stranger-' . bin2hex(random_bytes(4)) . '@joder.dev';
        $user->password  = 'not-a-real-hash';
        $user->nameFirst = 'Stran';
        $user->nameLast  = 'Ger';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function account(User $user, string $email): Account
    {
        $account = new Account();

        $account->usr      = $user;
        $account->name     = $email;
        $account->username = $email;
        $account->email    = $email;
        $account->authType = AuthType::Password->value;
        $account->isActive = true;
        $account->imapHost = 'imap.example.test';

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /** A connected, working OAuth account — the healthy baseline. */
    private function oauthAccount(User $user, string $email): Account
    {
        $account = new Account();

        $account->usr                = $user;
        $account->name               = $email;
        $account->username           = $email;
        $account->email              = $email;
        $account->authType           = AuthType::OAuth2->value;
        $account->oauthProvider      = MailProvider::Google->value;
        $account->oauthAccessToken   = 'access-token';
        $account->oauthRefreshToken  = 'refresh-token';
        $account->isActive           = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function calendar(User $user, string $name): Calendar
    {
        $calendar = new Calendar();

        $calendar->usr  = $user;
        $calendar->name = $name;

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    /** A calendar that mirrors a mail account's provider — role Remote. */
    private function remoteCalendar(User $user, Account $account, string $name): Calendar
    {
        $calendar = new Calendar();

        $calendar->usr      = $user;
        $calendar->account  = $account;
        $calendar->name     = $name;
        $calendar->role     = CalendarRole::Remote;
        $calendar->remoteId = 'remote-' . bin2hex(random_bytes(4));

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }
}
