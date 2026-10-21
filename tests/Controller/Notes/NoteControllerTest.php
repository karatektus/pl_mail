<?php

declare(strict_types=1);

namespace App\Tests\Controller\Notes;

use App\Entity\Note\Note;
use App\Repository\Note\NoteRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The web notes page writes the same rows Note/get reads: a note made here
 * shows on the phone, with the checklist the phone ticks.
 */
final class NoteControllerTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    public function testAChecklistTakenOnTheWebIsANoteWithItemsThatTick(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $crawler = $client->request('GET', '/notes');
        self::assertResponseIsSuccessful();

        $title = 'Groceries ' . bin2hex(random_bytes(3));
        $form  = $crawler->filter('form[method="post"][action="/notes"]')->form([
            'title'     => $title,
            'body'      => "milk\n[x] eggs\n\n",
            'checklist' => '1',
            'color'     => 'yellow',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/notes');

        $note = static::getContainer()->get(NoteRepository::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Note::class, $note);
        self::assertSame('yellow', $note->color);
        self::assertSame('', $note->body);
        self::assertSame([['text' => 'milk', 'done' => false], ['text' => 'eggs', 'done' => true]], $note->items);

        $crawler = $client->request('GET', '/notes/' . $note->id);
        self::assertResponseIsSuccessful();

        $toggle = (string) $crawler->filter('form[action="/notes/' . $note->id . '/close"] input[name="_token"]')->attr('value');
        $client->request('POST', '/notes/' . $note->id . '/toggle', ['_token' => $toggle, 'item' => 0]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $note = $em->find(Note::class, $note->id);
        self::assertTrue($note->items[0]['done']);

        // The editor's autosave replaces the whole note, labels included.
        $client->request('POST', '/notes/' . $note->id . '/autosave', [], [], [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X-CSRF-Token' => $toggle,
        ], json_encode([
            'title'     => $title,
            'checklist' => false,
            'body'      => "# Shop\n**milk**",
            'color'     => 'blue',
            'labels'    => ['Home', 'home', ' Errands '],
        ]));
        self::assertResponseIsSuccessful();

        $em->clear();
        $note = $em->find(Note::class, $note->id);
        self::assertSame("# Shop\n**milk**", $note->body);
        self::assertSame([], $note->items);
        self::assertSame('blue', $note->color);
        self::assertSame(['Home', 'Errands'], $note->labels);

        // A reminder: set with an offset, stored as the instant in UTC.
        $client->request('POST', '/notes/' . $note->id . '/reminder', [], [], [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X-CSRF-Token' => $toggle,
        ], json_encode(['at' => '2020-01-02T10:30:00+02:00']));
        self::assertResponseIsSuccessful();
        self::assertSame('2020-01-02T08:30:00Z', json_decode((string) $client->getResponse()->getContent(), true)['at']);

        $em->clear();
        $note = $em->find(Note::class, $note->id);
        self::assertSame('2020-01-02 08:30', $note->reminderAt?->format('Y-m-d H:i'));
        self::assertNull($note->reminderSentAt);

        // Due long ago: the sweep claims nothing older than an hour. Due a
        // minute ago: it claims it, once.
        $repo = static::getContainer()->get(NoteRepository::class);
        $now  = new \DateTimeImmutable('2020-01-02 08:31', new \DateTimeZone('UTC'));
        self::assertContains($note->id, array_map(static fn (Note $n) => $n->id, $repo->findDueReminders($now, $now->modify('-1 hour'))));
        self::assertTrue($repo->claimReminder($note, $now));
        self::assertFalse($repo->claimReminder($note, $now));

        $client->request('POST', '/notes/' . $note->id . '/reminder', [], [], [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X-CSRF-Token' => $toggle,
        ], json_encode(['at' => null]));
        $em->clear();
        $note = $em->find(Note::class, $note->id);
        self::assertNull($note->reminderAt);

        $client->request('POST', '/notes/' . $note->id . '/destroy', ['_token' => $toggle]);
        self::assertResponseRedirects('/notes?view=trash');
    }
}
