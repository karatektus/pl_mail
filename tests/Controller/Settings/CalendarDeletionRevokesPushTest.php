<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Tests\Support\Push\ScriptedCalendarPushManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Deleting a calendar calls its push registration off.
 *
 * WHAT WAS OPEN
 * ─────────────
 * Nothing anywhere called GoogleCalendarPushManager::unsubscribe() or
 * GraphCalendarPushManager::unsubscribe() on a calendar-removal path — the only
 * callers were `app:calendar:push` and each manager's own internal
 * re-subscribe. So every calendar a user deleted, unticked on the subscribe
 * screen, or lost by disconnecting a connection left Google holding a live
 * watch channel aimed at this install. Google kept POSTing to
 * /google/calendar/push about a calendar that no longer existed, the endpoint
 * could not resolve it, and the only thing that ever stopped it was the channel
 * expiring on its own a week later.
 *
 * The account-deletion path had done this correctly all along
 * (AccountController), which is what made the omission easy to miss: the
 * mechanism existed and was simply never wired to the other four doors.
 */
final class CalendarDeletionRevokesPushTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private ScriptedCalendarPushManager $push;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testDeletingAMirroredCalendarHandsBackItsChannel(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $calendar = $this->mirroredCalendar($user);

        $client->request('POST', '/settings/calendars/' . $calendar->id . '/delete', [
            '_token' => $this->token($client, 'calendar-delete' . $calendar->id),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [ScriptedCalendarPushManager::MARKER],
            $this->push->revoked,
            'the channel was handed back',
        );

        // And the deletion the user asked for actually happened.
        self::assertNull($this->em->find(Calendar::class, $calendar->id));
    }

    /**
     * A local calendar has no registration anywhere, so there is nothing to
     * hand back and nothing to report. The registry answers null and the
     * teardown skips quietly — see PushTeardown.
     */
    public function testDeletingALocalCalendarRevokesNothing(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $calendar = $this->mirroredCalendar($user);

        // No remoteId at all: nothing was ever registered for this one.
        $calendar->remoteId = null;
        $calendar->role     = CalendarRole::Custom;
        $this->em->flush();

        $client->request('POST', '/settings/calendars/' . $calendar->id . '/delete', [
            '_token' => $this->token($client, 'calendar-delete' . $calendar->id),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->push->revoked);
        self::assertNull($this->em->find(Calendar::class, $calendar->id));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * A token minted against the browser's own session.
     *
     * The GET is not incidental: the token store is the session, and there is
     * no session until the client has made a request. The carrier request is
     * how the container-side token manager is pointed at that same session —
     * the pattern AdminDataResetTest established.
     */
    private function token(KernelBrowser $client, string $id): string
    {
        $client->request('GET', '/settings?section=calendars');

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
        $this->push       = $container->get(ScriptedCalendarPushManager::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->disableReboot();

        $this->connection->beginTransaction();

        return $this->em->find(User::class, $user->id);
    }

    private function mirroredCalendar(User $user): Calendar
    {
        $calendar = new Calendar();

        $calendar->usr      = $user;
        $calendar->name     = 'Mirrored';
        $calendar->role     = CalendarRole::Remote;
        $calendar->remoteId = ScriptedCalendarPushManager::MARKER;
        // Never the default — CalendarSettingsController refuses to delete that
        // one, and the refusal is a different test's subject.
        $calendar->isDefault = false;

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }
}
