<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;

/**
 * What a repair control says the moment it is pressed, and what it says after.
 *
 * ── The defect this pins ─────────────────────────────────────────────────────
 * "the try syncing now button has no userfacing immediate effect. it should
 * tell the user immediately whats happening and be disabled until its finished
 * doing that" — and "that counts for all those controls". Every repair was a
 * bare submit button in a plain form, so the only feedback was a page change
 * arriving whenever the round trip finished, with nothing to stop a second
 * press in the meantime.
 *
 * ── Why these assertions and not others ──────────────────────────────────────
 * The disabling itself is Turbo's, not ours: `data-turbo-submits-with` makes
 * Turbo disable the submitter, swap its label, and restore both on
 * turbo:submit-end — including after a failure, which is the case a hand-rolled
 * version usually gets wrong. Re-testing Turbo here would be testing somebody
 * else's library, so what is pinned server-side is the CONTRACT this app owes
 * it: that every control carries the attribute, that what the attribute carries
 * is a translated sentence rather than a key or a hardcoded string, and that
 * the sentence is honest about what the press achieved. The browser half — the
 * button really going disabled, coming back after a failure, and surviving the
 * back button — is tests/e2e/account-health.spec.ts, because it only exists in
 * a browser.
 *
 * The awaiting state below is the part that is genuinely this app's problem.
 * resyncCalendar only DISPATCHES a message: when the redirect lands, the sync
 * has not happened. A card that re-rendered the enabled button in that gap
 * would be inviting a second dispatch of work already queued, and one that said
 * "Synced" would be lying. So it says the sync was started, and goes on saying
 * it until the calendar's own stored state says otherwise.
 */
final class AccountHealthRepairFeedbackTest extends WebTestCase
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

    // ── every control says what it is doing ──────────────────────────────────

    /**
     * The calendar resync, which is the one the report was written about.
     *
     * "Starting sync…" and not "Syncing…" or "Synced": the controller clears
     * the backoff, dispatches, and redirects. Started is the whole of what the
     * press achieved by the time the page comes back.
     */
    public function testTheCalendarResyncSaysWhatItIsDoingAndIsDisabledWhileItDoesIt(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $account  = $this->oauthAccount($user, 'resync-feedback@joder.dev');
        $calendar = $this->remoteCalendar($user, $account, 'Feiertage');

        $calendar->recordSyncFailure('The calendar service refused the request.');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');
        $button  = $crawler->filter(
            '[data-health-issue="calendar-' . $calendar->id . '"] form[action*="/resync"] button[type=submit]',
        );

        self::assertSame(1, $button->count());
        self::assertSame(
            'Starting sync…',
            $button->attr('data-turbo-submits-with'),
            'the label Turbo swaps in says the sync was STARTED, which is all the press does',
        );
        self::assertSame(
            'Try syncing now',
            trim($button->text()),
            'and the resting label is unchanged — the pending one replaces it, it does not become it',
        );
    }

    /**
     * The attribute has to be on the BUTTON. Turbo reads
     * `data-turbo-submits-with` off the submitter only (FormSubmission#
     * submitsWith); on the <form> it is silently ignored, which would look
     * exactly like this feature working in review and doing nothing in use.
     */
    public function testThePendingLabelIsOnTheSubmitterWhereTurboLooksForIt(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $account  = $this->oauthAccount($user, 'submitter@joder.dev');
        $calendar = $this->remoteCalendar($user, $account, 'Submitter');

        $calendar->recordSyncFailure('nope');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');
        $form    = $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"] form[action*="/resync"]');

        self::assertNull(
            $form->attr('data-turbo-submits-with'),
            'not on the form, where Turbo never looks',
        );
        self::assertNotNull($form->filter('button[data-turbo-submits-with]')->attr('data-turbo-submits-with'));
    }

    /**
     * Both queue repairs, including the destructive one.
     *
     * The discard keeps its confirm dialog, and Turbo raises that BEFORE it
     * starts the submission — so a pending state cannot make it easier to fire.
     * It also keeps the outline-and-danger styling rather than the accent fill
     * of the safe repairs, which is the section docblock's second rule and the
     * thing a pending state could most easily erode.
     */
    public function testBothQueueRepairsSayWhatTheyAreDoingAndTheDestructiveOneStaysDistinct(): void
    {
        $client = static::createClient();
        $this->boot($client);
        $this->abandonOneJob();

        $crawler = $client->request('GET', '/settings?section=health');
        $card    = $crawler->filter('[data-health-issue="queue-abandoned"]');

        self::assertSame(1, $card->count(), 'the abandoned job is reported');

        $retry = $card->filter('form[action*="/queue/retry"] button[type=submit]');

        self::assertSame('Putting them back…', $retry->attr('data-turbo-submits-with'));

        $discardForm = $card->filter('form[action*="/queue/discard"]');
        $discard     = $discardForm->filter('button[type=submit]');

        self::assertSame('Discarding…', $discard->attr('data-turbo-submits-with'));

        // The confirm survives. It is what actually guards this button, and the
        // pending state is reached only once it has been answered.
        self::assertNotNull(
            $discardForm->attr('data-turbo-confirm'),
            'the destructive repair still asks before it does anything',
        );

        // And it still does not look like a safe repair.
        $classes = (string) $discard->attr('class');

        self::assertStringContainsString('text-danger', $classes);
        self::assertStringNotContainsString('bg-accent', $classes, 'never the primary');
    }

    /**
     * The reconnect is a link, and links have no submission for Turbo to
     * instrument. It leaves for the provider — which on a cold OAuth redirect
     * is a blank second or more with no feedback whatsoever — so it names the
     * provider it is taking you to, and the controller that writes that in has
     * both a target and an action to reach it by.
     */
    public function testTheReconnectLinkCarriesItsOwnPendingLabelNamingTheProvider(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->oauthAccount($user, 'leaving@joder.dev');

        $account->oauthLastRefreshError = 'invalid_grant';
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');
        $link    = $crawler->filter('[data-health-issue="account-' . $account->id . '"] a[href*="/reconnect/"]');

        self::assertSame(1, $link->count());
        self::assertSame(
            'Taking you to Gmail…',
            $link->attr('data-health-pending-label'),
            'a provider named, not "please wait"',
        );
        self::assertSame('leaving', $link->attr('data-ui--health-repair-target'));
        self::assertStringContainsString('ui--health-repair#leave', (string) $link->attr('data-action'));
    }

    /**
     * The section carries the controller and its whole vocabulary, translated.
     *
     * The rule is that no user-facing string is spelled in JavaScript. This
     * asserts the delivery mechanism rather than trusting the controller's
     * fallbacks: a missing key here renders as an English default nobody
     * translated, which is the exact failure the rule exists to prevent.
     */
    public function testEveryWordTheControllerWritesArrivesTranslatedFromTwig(): void
    {
        $client = static::createClient();
        $this->boot($client);

        $crawler = $client->request('GET', '/settings?section=health');
        $section = $crawler->filter('#settings-health[data-controller="ui--health-repair"]');

        self::assertSame(1, $section->count());

        $i18n = json_decode(
            (string) $section->attr('data-ui--health-repair-i18n-value'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['leaving', 'synced', 'failedAgain', 'stillWaiting'],
            array_keys($i18n),
        );

        foreach ($i18n as $key => $value) {
            self::assertNotSame('', $value, $key . ' has words');
            self::assertStringNotContainsString('settings.health.', $value, $key . ' is translated, not a key');
        }
    }

    // ── queued is not finished ───────────────────────────────────────────────

    /**
     * The honest middle state, and the reason it is derived server-side.
     *
     * After the POST the card must not show an enabled button — the message is
     * already on the queue — and must not claim success. It says the sync was
     * started, and it says it from the calendar's own stored columns, so a
     * reload lands on the same answer rather than reverting to the button.
     */
    public function testAfterPressingResyncTheCardSaysStartedRatherThanOfferingTheButtonAgain(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $account  = $this->oauthAccount($user, 'awaiting@joder.dev');
        $calendar = $this->remoteCalendar($user, $account, 'Awaiting');

        $calendar->recordSyncFailure('The calendar service refused the request.');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');
        $form    = $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"] form[action*="/resync"]');

        $client->request('POST', (string) $form->attr('action'), [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects('/settings?section=health');

        $crawler = $client->followRedirect();
        $card    = $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"]');

        $awaiting = $card->filter('[data-health-awaiting]');

        self::assertSame(1, $awaiting->count(), 'the card says something is under way');
        self::assertSame(
            (string) $calendar->id,
            $awaiting->attr('data-health-calendar-id'),
            'and names which calendar, so the live update can find it again',
        );
        self::assertStringContainsString('Sync started', $awaiting->text());

        // The flash says started too, not finished — and it is actually on the
        // page, which it never used to be: addFlash() was called by every
        // repair here and NOTHING in the app rendered the flash bag, so the
        // only feedback a repair gave was the page reappearing unchanged. See
        // the toast-region block in _layout/app.html.twig.
        $toast = $crawler->filter('#toast-region [data-flash-toast="success"]');

        self::assertSame(1, $toast->count(), 'the flash reaches the page at all');
        self::assertStringContainsString('has been asked to sync', $toast->text());
        self::assertStringNotContainsString(
            'Reload in a moment',
            $toast->text(),
            'the old wording implied the work was already done',
        );

        // The button is not offered. It is still in the DOM, hidden, carrying a
        // real token — that is the way back out when the sync reports a failure
        // or nothing is heard at all — but it is not something to press now.
        $retry = $awaiting->filter('[data-health-retry]');

        self::assertSame(1, $retry->count());
        self::assertStringContainsString('hidden', (string) $retry->attr('class'));
        self::assertSame(
            1,
            $retry->filter('form[action*="/resync"] input[name="_token"]')->count(),
            'the way back carries a token, which is why it is rendered here and not built in JavaScript',
        );

        // And a reload lands on the same answer rather than flipping back.
        $crawler = $client->request('GET', '/settings?section=health');

        self::assertSame(
            1,
            $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"] [data-health-awaiting]')->count(),
            'the waiting state is stored state, not a one-shot flash',
        );
    }

    /**
     * The failure path, which is the one that must not silently revert.
     *
     * A sync that fails again records a failure, and that alone takes the card
     * out of the waiting state and hands the button back — the user pressed a
     * repair, it did not work, and the card says what it said this time rather
     * than pretending the press never happened.
     */
    public function testASyncThatFailsAgainEndsTheWaitAndOffersTheButtonBack(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $account  = $this->oauthAccount($user, 'failed-again@joder.dev');
        $calendar = $this->remoteCalendar($user, $account, 'Fails again');

        $calendar->recordSyncFailure('The first reason.');
        $calendar->clearSyncBackoff();
        $this->em->flush();

        self::assertTrue($calendar->isAwaitingRequestedSync(), 'set up in the waiting state');

        // What the worker does when the retry fails.
        $calendar->recordSyncFailure('The calendar no longer exists at the remote.');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');
        $card    = $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"]');

        self::assertSame(0, $card->filter('[data-health-awaiting]')->count(), 'it is no longer waiting');
        self::assertSame(
            1,
            $card->filter('form[action*="/resync"] button[type=submit]')->count(),
            'and the button is offered again rather than the control being left dead',
        );
        self::assertStringContainsString(
            'The calendar no longer exists at the remote.',
            $card->filter('[data-health-detail]')->text(),
            'explained by THIS failure, not the one before it',
        );
    }

    /** A sync that works takes the whole card with it. */
    public function testASyncThatWorksRemovesTheCardEntirely(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $account  = $this->oauthAccount($user, 'recovered@joder.dev');
        $calendar = $this->remoteCalendar($user, $account, 'Recovered');

        $calendar->recordSyncFailure('The first reason.');
        $calendar->clearSyncBackoff();
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertSame(1, $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"]')->count());

        $calendar->recordSyncSuccess();
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=health');

        self::assertSame(
            0,
            $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"]')->count(),
            'the card stops claiming a problem that has just been fixed',
        );
    }

    /**
     * The waiting state must not fire on a calendar nobody asked about.
     *
     * This is the false-positive rule applied to the new state: it is derived
     * from three columns rather than stored, so the derivation has to be exact
     * or an ordinary broken calendar renders as one being repaired and loses
     * its button to a wait that will never end.
     */
    public function testACalendarThatWasNeverAskedToRetryStillShowsItsButton(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $account  = $this->oauthAccount($user, 'untouched@joder.dev');
        $calendar = $this->remoteCalendar($user, $account, 'Untouched');

        $calendar->recordSyncFailure('Broken, and nobody has pressed anything.');
        $this->em->flush();

        self::assertFalse($calendar->isAwaitingRequestedSync());

        $crawler = $client->request('GET', '/settings?section=health');
        $card    = $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"]');

        self::assertSame(0, $card->filter('[data-health-awaiting]')->count());
        self::assertSame(1, $card->filter('form[action*="/resync"] button[type=submit]')->count());
    }

    /**
     * A consequence has no resync button at all — offering one under a dead
     * grant is offering a button whose entire behaviour is to fail — so it must
     * not acquire a waiting state either, whatever its columns happen to say.
     */
    public function testACalendarUnderADeadGrantGetsNeitherAButtonNorAWait(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->oauthAccount($user, 'dead-grant@joder.dev');

        $account->oauthLastRefreshError = 'invalid_grant';

        $calendar = $this->remoteCalendar($user, $account, 'Downstream');
        $calendar->recordSyncFailure('Google would not renew the sign-in.');
        $calendar->clearSyncBackoff();
        $this->em->flush();

        self::assertTrue($calendar->isAwaitingRequestedSync(), 'the columns say waiting');

        $crawler = $client->request('GET', '/settings?section=health');
        $card    = $crawler->filter('[data-health-issue="calendar-' . $calendar->id . '"]');

        self::assertSame(1, $card->count());
        self::assertSame(0, $card->filter('form[action*="/resync"]')->count());
        self::assertSame(
            0,
            $card->filter('[data-health-awaiting]')->count(),
            'the repair for this is the reconnect above, so there is nothing here to be waiting on',
        );
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

        $this->em->createQuery(
            'DELETE FROM ' . Calendar::class . ' c WHERE c.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->createQuery(
            'DELETE FROM ' . Account::class . ' a WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    /**
     * One envelope on the failure transport, so the queue card exists.
     *
     * Sent to the real transport rather than stubbed: `failed` is
     * `doctrine://default?queue_name=failed`, so it lands on the connection this
     * test already holds a transaction open on and is rolled back in tearDown
     * with everything else. QueueMonitor reads it through the same listable
     * receiver the page does, which is the point — a stubbed monitor would
     * assert the template against a shape nothing produces.
     */
    private function abandonOneJob(): void
    {
        $transport = static::getContainer()->get('messenger.transport.failed');

        $transport->send(
            new Envelope(new SyncCalendarMessage(0), [
                new ErrorDetailsStamp(\RuntimeException::class, 0, 'Something gave up.'),
            ]),
        );
    }

    private function oauthAccount(User $user, string $email): Account
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
