<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Send later, from the web composer.
 *
 * The composer was the only surface that never set submissionSendAt: JMAP has
 * had delayed send end to end for a while, and the send pill's chevron was a
 * button with no handler. So the things worth proving are the ones where the
 * two surfaces have to agree — the hold is recorded on the same column
 * EmailSubmission/get reads as `pending`, the envelope is held for the same
 * distance, the ceiling is the same one advertised as `maxDelayedSend`, and
 * cancelling puts everything back where an unsent draft leaves it.
 *
 * And the timezone, which is the one that would fail silently. A schedule that
 * fires at the wrong hour is the whole feature failing, and it fails by an
 * amount nobody notices in a test that reads its expectation off the same
 * clock the code did.
 */
final class ScheduledSendTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    /** Deliberately far from anything a container would default to. */
    private const string USER_ZONE = 'Australia/Sydney';

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
     * The hold is recorded, in the user's own zone, and the envelope is held
     * for exactly as long as the hold has left to run.
     */
    public function testSchedulingRecordsTheHoldInTheUsersTimezone(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'scheduler@joder.dev');

        $user->timezone = self::USER_ZONE;
        $this->em->flush();

        $zone = new DateTimeZone(self::USER_ZONE);
        $wall = (new DateTimeImmutable('+3 days', $zone))->format('Y-m-d') . 'T09:00';

        $this->transport()->reset();
        $this->schedule($client, $account, $wall);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'turbo-stream',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );

        $message = $this->lastDraft($account);

        self::assertNotNull($message->submissionSendAt, 'the hold is on the row');
        self::assertNull($message->sentAt, 'and nothing has gone out');

        // The whole point: 09:00 means 09:00 in Sydney, not 09:00 wherever the
        // container thinks it is. Read as UTC these differ by the offset, which
        // is between nine and eleven hours — never zero.
        $expected = new DateTimeImmutable($wall, $zone);
        $naive    = new DateTimeImmutable($wall, new DateTimeZone('UTC'));

        self::assertSame(
            $expected->getTimestamp(),
            $message->submissionSendAt->getTimestamp(),
            'read in the configured zone',
        );
        self::assertNotSame(
            $naive->getTimestamp(),
            $message->submissionSendAt->getTimestamp(),
            'and demonstrably not in the server default',
        );

        // The envelope is held for the distance to that instant, not for the
        // ten seconds the ordinary send guard uses.
        $delay = $this->lastDelayMs();

        self::assertNotNull($delay, 'a send job was dispatched');
        self::assertGreaterThan(
            2 * 86_400 * 1000,
            $delay,
            'held for days, not for the ten-second undo window',
        );
        self::assertEqualsWithDelta(
            ($expected->getTimestamp() - time()) * 1000,
            $delay,
            5_000,
            'the delay is the distance to the send time',
        );
    }

    /**
     * Cancelling puts the draft back exactly where an unsent draft sits.
     *
     * Both halves matter. `cancelled` is what SendMessageHandler reads when the
     * envelope finally comes due; clearing submissionSendAt is what stops
     * EmailSubmission/get — and therefore every other device — from going on
     * showing a schedule for mail that has been called off.
     */
    public function testCancellingClearsTheHold(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'canceller@joder.dev');

        $user->timezone = self::USER_ZONE;
        $this->em->flush();

        $wall = (new DateTimeImmutable('+2 days', new DateTimeZone(self::USER_ZONE)))->format('Y-m-d') . 'T09:00';

        $this->schedule($client, $account, $wall);

        $message = $this->lastDraft($account);

        self::assertNotNull($message->submissionSendAt);

        $client->request('POST', '/compose/undo/' . $message->id);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->find(Message::class, $message->id);

        self::assertNull($reloaded->submissionSendAt, 'no schedule left to show anywhere');
        self::assertTrue($reloaded->cancelled, 'and the handler will drop the envelope');
        self::assertNull($reloaded->sentAt);
    }

    /**
     * The toast is the whole of the positive feedback, so it has to be read in
     * the user's own clock — not the container's, and not 24-hour because that
     * is what a server writes.
     *
     * 09:00 in Sydney is 23:00 the previous day in UTC, so a toast rendered
     * against the wrong zone gets both the hour and the DAY wrong; a toast
     * rendered against the wrong format prints "9:00" where the user has asked
     * for "9:00 am" everywhere else in the app.
     */
    public function testTheToastNamesTheTimeInTheUsersZoneAndClock(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'toast@joder.dev');

        $user->timezone = self::USER_ZONE;
        $user->setSetting(User::SETTING_CLOCK, '12');
        $this->em->flush();

        $zone = new DateTimeZone(self::USER_ZONE);
        $day  = (new DateTimeImmutable('+3 days', $zone));

        $this->schedule($client, $account, $day->format('Y-m-d') . 'T09:00');

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Scheduled for', $body);
        self::assertStringContainsString(
            '9:00 am',
            $body,
            'the chosen clock format, not the server default',
        );
        self::assertStringContainsString(
            $day->format('j'),
            $body,
            'and the day it is 09:00 on in Sydney, not the one it is in UTC',
        );

        // The window goes with it — a schedule that leaves the composer open
        // looks like nothing happened.
        self::assertStringContainsString('target="compose_dock"', $body);

        // And the toast carries the way back out, labelled for a hold rather
        // than for a send already on its way.
        self::assertStringContainsString('Cancel send', $body);
    }

    /**
     * Calling the hold off from the list row: the same clearing as undo(), but
     * the answer is the row rather than a reopened composer.
     */
    public function testUnschedulingFromTheRowClearsTheHoldAndRedrawsTheRow(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'rowcancel@joder.dev');

        $user->timezone = self::USER_ZONE;
        $this->em->flush();

        $wall = (new DateTimeImmutable('+2 days', new DateTimeZone(self::USER_ZONE)))->format('Y-m-d') . 'T09:00';

        $this->schedule($client, $account, $wall);

        $message  = $this->lastDraft($account);
        $threadId = $message->thread->id;

        self::assertNotNull($message->submissionSendAt);

        $client->request('POST', '/compose/unschedule/' . $message->id . '?type=thread&draft_scope=1');

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(
            'target="thread_' . $threadId . '"',
            $body,
            'the row is patched where it stands, not reloaded around it',
        );
        self::assertStringNotContainsString(
            'data-scheduled-badge',
            $body,
            'and comes back without the badge',
        );
        self::assertStringContainsString('Scheduled send cancelled', $body);

        // Nothing about the composer: this was clicked from a list.
        self::assertStringNotContainsString('compose-window', $body);

        $this->em->clear();
        $reloaded = $this->em->find(Message::class, $message->id);

        self::assertNull($reloaded->submissionSendAt, 'no schedule left to show anywhere');
        self::assertTrue($reloaded->cancelled, 'and the handler will drop the envelope');
        self::assertNull($reloaded->sentAt);
    }

    /**
     * A row whose mail already went out while the list sat open.
     *
     * The click cannot un-send it, and must not pretend to: submissionSendAt is
     * by then the record of when the mail was due — EmailSubmission/get falls
     * back to it — and `cancelled` on a handled message is a lie the next
     * re-submission would trip over. The row is redrawn either way, because
     * what the user was looking at was already stale.
     */
    public function testUnschedulingAMessageThatHasAlreadyGoneChangesNothing(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'toolate@joder.dev');

        $this->em->flush();

        $wall = (new DateTimeImmutable('+2 days'))->format('Y-m-d') . 'T09:00';

        $this->schedule($client, $account, $wall);

        $message = $this->lastDraft($account);
        $due     = $message->submissionSendAt;

        self::assertNotNull($due);

        // The hold came due while the list sat open.
        $message->sentAt    = new DateTimeImmutable('now');
        $message->cancelled = false;
        $this->em->flush();

        $client->request('POST', '/compose/unschedule/' . $message->id . '?type=thread&draft_scope=1');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            'Scheduled send cancelled',
            (string) $client->getResponse()->getContent(),
            'nothing was cancelled, so nothing claims to have been',
        );

        $this->em->clear();
        $reloaded = $this->em->find(Message::class, $message->id);

        self::assertNotNull($reloaded->sentAt, 'still sent');
        self::assertFalse($reloaded->cancelled, 'and not retroactively called off');
        self::assertSame(
            $due->getTimestamp(),
            $reloaded->submissionSendAt?->getTimestamp(),
            'the record of when it was due survives',
        );
    }

    /** A time already gone is refused, and nothing is dispatched for it. */
    public function testATimeInThePastIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'past@joder.dev');

        $this->em->flush();
        $this->transport()->reset();

        $wall = (new DateTimeImmutable('-1 day'))->format('Y-m-d') . 'T09:00';

        $this->schedule($client, $account, $wall);

        // 422, like every other rejected compose submission: the window comes
        // back carrying its error, which is the status Turbo re-renders on.
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->transport()->getSent(), 'nothing was queued');
        self::assertNull($this->lastDraftOrNull($account)?->submissionSendAt);
    }

    /**
     * And so is one past the ceiling JMAP advertises — the web composer must
     * not accept a hold EmailSubmission/set would refuse.
     */
    public function testATimeBeyondTheAdvertisedCeilingIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'toofar@joder.dev');

        $this->em->flush();
        $this->transport()->reset();

        $wall = (new DateTimeImmutable('+40 days'))->format('Y-m-d') . 'T09:00';

        $this->schedule($client, $account, $wall);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->transport()->getSent());
        self::assertNull($this->lastDraftOrNull($account)?->submissionSendAt);
    }

    /**
     * A schedule runs the send path's recipient check, not the draft path's.
     *
     * The reason this is not paranoia: the refusal would otherwise surface on
     * the day the hold expires, as a bounce nobody is watching for, hours or
     * days after the window that could have said so was closed.
     */
    public function testAnUnsendableRecipientIsRefusedBeforeTheHoldIsSet(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'invalid@joder.dev');

        $this->em->flush();
        $this->transport()->reset();

        $wall = (new DateTimeImmutable('+2 days'))->format('Y-m-d') . 'T09:00';

        $this->schedule($client, $account, $wall, recipient: 'keine-gueltige-adresse');

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->transport()->getSent());
        self::assertStringContainsString(
            'recipient',
            strtolower((string) $client->getResponse()->getContent()),
        );
    }

    /** The chevron is a real menu now, and it names the presets. */
    public function testTheSendPillOffersTheScheduleMenu(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);

        $this->account($user, 'menu@joder.dev');
        $this->em->flush();

        $crawler = $client->request('GET', '/compose/new');

        self::assertResponseIsSuccessful();

        self::assertCount(
            1,
            $crawler->filter('input[name="schedule_at"]'),
            'the field a preset writes into',
        );
        self::assertCount(
            3,
            $crawler->filter('[data-compose--schedule-target="option"]'),
            'tomorrow morning, tomorrow afternoon, Monday morning',
        );
        self::assertCount(
            1,
            $crawler->filter('input[type="datetime-local"][data-compose--schedule-target="input"]'),
            'and a native picker, no library',
        );

        // The zone the menu computes against is the configured one, handed in
        // rather than guessed at in the browser.
        self::assertNotSame(
            '',
            (string) $crawler->filter('[data-compose--schedule-timezone-value]')
                ->attr('data-compose--schedule-timezone-value'),
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function schedule(
        KernelBrowser $client,
        Account $account,
        string $wallClock,
        string $recipient = 'rike@example.test',
    ): void {
        $crawler = $client->request('GET', '/compose/new');
        $token   = $crawler->filter('input[name="compose[_token]"]')->attr('value');

        $client->request('POST', '/compose/schedule', [
            'schedule_at' => $wallClock,
            'compose'     => [
                '_token'      => $token,
                'account'     => $account->id . '|' . $account->email,
                'toAddresses' => [$recipient],
                'subject'     => 'Later',
                'bodyHtml'    => '<p>Not yet.</p>',
            ],
        ]);
    }

    private function lastDraft(Account $account): Message
    {
        $message = $this->lastDraftOrNull($account);

        self::assertNotNull($message, 'the schedule saved a draft');

        return $message;
    }

    private function lastDraftOrNull(Account $account): ?Message
    {
        $this->em->clear();

        return $this->em->createQuery(
            'SELECT m FROM ' . Message::class . ' m WHERE m.account = :account ORDER BY m.id DESC',
        )->setParameter('account', $account->id)->setMaxResults(1)->getOneOrNullResult();
    }

    /** The DelayStamp on the most recent send job, in milliseconds. */
    private function lastDelayMs(): ?int
    {
        $sent = $this->transport()->getSent();

        foreach (array_reverse($sent) as $envelope) {
            if (false === $envelope->getMessage() instanceof SendMessageMessage) {
                continue;
            }

            return $envelope->last(DelayStamp::class)?->getDelay();
        }

        return null;
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.export');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    // ── fixture ───────────────────────────────────────────────────────────

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

        // The fixtures live in a transaction rolled back in tearDown, so they
        // exist only on this connection — a rebooted kernel would build a new
        // one and see none of them.
        $client->disableReboot();

        $this->connection->beginTransaction();

        $this->em->createQuery(
            'UPDATE ' . Account::class . ' a SET a.isActive = false WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email): Account
    {
        $account = new Account();

        $account->usr       = $user;
        $account->name      = $email;
        $account->username  = $email;
        $account->email     = $email;
        $account->authType  = 'password';
        $account->isActive  = true;
        $account->isPrimary = true;
        $account->sortOrder = 0;
        $account->imapHost  = 'imap.example.test';

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
