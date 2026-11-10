<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Repository\Calendar\EventSourceLinkRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Create an event from this message": the editor opens titled after the mail,
 * and the saved event keeps the mail as its provenance.
 *
 * Through real requests, because the message id travels from the menu's URL
 * into a hidden field and back on the save — a test that called the writer
 * would skip exactly the hops that can lose it.
 */
final class EventFromMessageTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheEditorOpensTitledAfterTheMailAndTheSaveLinksIt(): void
    {
        $client  = $this->signIn();
        $message = $this->message($this->user, 'Dossier à rendre avant le 20 novembre');

        $crawler = $client->request('GET', '/calendar/event/new?message=' . $message->id);

        self::assertResponseIsSuccessful();
        self::assertSame(
            'Dossier à rendre avant le 20 novembre',
            $crawler->filter('input[name="title"]')->attr('value'),
        );
        self::assertSame(
            (string) $message->id,
            $crawler->filter('input[name="sourceMessage"]')->attr('value'),
        );

        $this->save($client, $crawler, 'Rappel dossier', (string) $message->id);

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        $event = $this->event('Rappel dossier');

        $source = static::getContainer()->get(EventSourceLinkRepository::class)
            ->findLatestAppliedMessageByEvent([$event])[(int) $event->id] ?? null;

        self::assertSame($message->id, $source?->id);

        // Still an event the user typed: no "Not an event", nothing for the
        // reconciler to revise.
        self::assertSame(EventSource::Manual, $event->source);
        self::assertFalse($event->isExtracted());

        $client->request('GET', '/calendar/event/' . $event->id . '/details');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('a[href$="/%d"]', $message->id));
    }

    /**
     * The id is a query parameter and then a hidden field; both are the
     * user's to edit. Someone else's mail must neither title the editor nor
     * end up as the provenance of an event.
     */
    public function testAnotherUsersMessageIsIgnored(): void
    {
        $client  = $this->signIn();
        $other   = $this->otherUser();
        $message = $this->message($other, 'Not yours');

        $crawler = $client->request('GET', '/calendar/event/new?message=' . $message->id);

        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $crawler->filter('input[name="title"]')->attr('value'));
        self::assertCount(0, $crawler->filter('input[name="sourceMessage"]'));

        $this->save($client, $crawler, 'Forged', (string) $message->id);

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        $event = $this->event('Forged');

        self::assertCount(0, static::getContainer()->get(EventSourceLinkRepository::class)->findBy([
            'event' => $event,
        ]));
    }

    /** A refused save comes back with the mail still attached. */
    public function testARefusedSaveKeepsTheMail(): void
    {
        $client  = $this->signIn();
        $message = $this->message($this->user, 'Keep me');

        $crawler = $client->request('GET', '/calendar/event/new?message=' . $message->id);
        $form    = $crawler->filter('form[action$="/calendar/event/save"]')->first();

        $client->request('POST', '/calendar/event/save', [
            '_token'        => (string) $form->filter('input[name="_token"]')->attr('value'),
            'eventId'       => 0,
            'timeZone'      => (string) $form->filter('input[name="timeZone"]')->attr('value'),
            'view'          => 'week',
            'title'         => 'Backwards',
            'startsAt'      => $this->day() . 'T16:00',
            'endsAt'        => $this->day() . 'T15:00',
            'calendars'     => $this->ticked($crawler),
            'repeat'        => 'none',
            'sourceMessage' => (string) $message->id,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            sprintf('name="sourceMessage" value="%d"', $message->id),
            (string) $client->getResponse()->getContent(),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function save(KernelBrowser $client, Crawler $crawler, string $title, string $sourceMessage): void
    {
        $form = $crawler->filter('form[action$="/calendar/event/save"]')->first();

        $client->request('POST', '/calendar/event/save', [
            '_token'        => (string) $form->filter('input[name="_token"]')->attr('value'),
            'eventId'       => 0,
            'timeZone'      => (string) $form->filter('input[name="timeZone"]')->attr('value'),
            'view'          => 'week',
            'title'         => $title,
            'startsAt'      => $this->day() . 'T09:00',
            'endsAt'        => $this->day() . 'T10:00',
            'calendars'     => $this->ticked($crawler),
            'repeat'        => 'none',
            'sourceMessage' => $sourceMessage,
        ]);
    }

    /** @return list<string> */
    private function ticked(Crawler $crawler): array
    {
        $ticked = $crawler
            ->filter('form input[name="calendars[]"][checked]')
            ->each(static fn ($node): string => (string) $node->attr('value'));

        self::assertNotSame([], $ticked, 'the editor must open with somewhere for the event to go');

        return $ticked;
    }

    private function event(string $title): CalendarEvent
    {
        $event = static::getContainer()->get(CalendarEventRepository::class)->findOneBy(['title' => $title]);

        self::assertInstanceOf(CalendarEvent::class, $event);

        return $event;
    }

    private function day(): string
    {
        return (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->format('Y-m-d');
    }

    private function message(User $owner, string $subject): Message
    {
        $account                 = new Account();
        $account->usr            = $owner;
        $account->name           = 'Event fixture';
        $account->email          = 'event-account@example.test';
        $account->username       = 'event-' . uniqid('', true) . '@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-11-09');
        $thread->unreadCount       = 0;
        $thread->messageCount      = 1;
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->fromAddress    = 'sender@example.test';
        $message->fromName       = 'Sender';
        $message->bodyText       = 'À rendre avant le 20 novembre.';
        $message->receivedAt     = new DateTimeImmutable('2026-11-09 09:00');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->messageId      = sprintf('<event-%s@example.test>', uniqid('', true));
        $thread->addMessage($message);
        $this->em->persist($message);

        $this->em->flush();

        return $message;
    }

    private function otherUser(): User
    {
        $user            = new User();
        $user->email     = 'event-other-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Other';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'event-from-message-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Event';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Personal';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'Europe/Paris';
        $calendar->isVisible = true;
        $calendar->isDefault = true;
        $calendar->sortOrder = 0;
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user = $user;

        $client->loginUser($user);

        return $client;
    }
}
