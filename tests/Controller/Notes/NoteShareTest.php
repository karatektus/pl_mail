<?php

declare(strict_types=1);

namespace App\Tests\Controller\Notes;

use App\Entity\Note\Note;
use App\Entity\Note\NoteShare;
use App\Repository\Note\NoteShareRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A note shared by link: the owner makes the link; someone with no account
 * opens it (past a password), and a collaborator link saves their edits.
 */
final class NoteShareTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    public function testAPasswordedCollaboratorLinkOpensAndSaves(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $em          = static::getContainer()->get(EntityManagerInterface::class);
        $note        = new Note();
        $note->usr   = $user;
        $note->title = 'Shared ' . bin2hex(random_bytes(3));
        $note->body  = 'before';
        $em->persist($note);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/notes/' . $note->id);
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form[action="/notes/' . $note->id . '/close"] input[name="_token"]')->attr('value');

        $client->request('GET', '/notes/' . $note->id . '/download.txt');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("before", (string) $client->getInternalResponse()->getContent());

        $client->request('GET', '/notes/' . $note->id . '/as-message');
        self::assertResponseIsSuccessful();
        $message = json_decode((string) $client->getInternalResponse()->getContent(), true);
        self::assertTrue($message['ok']);
        self::assertStringContainsString('before', $message['html']);

        $client->request('POST', '/notes/' . $note->id . '/shares', [
            '_token' => $token, 'password' => 'hunter2', 'expires' => '7', 'can_edit' => '1',
        ]);
        self::assertResponseRedirects();

        $shares = static::getContainer()->get(NoteShareRepository::class)->forNote($note);
        self::assertCount(1, $shares);
        $share = $shares[0];
        self::assertInstanceOf(NoteShare::class, $share);
        self::assertTrue($share->canEdit);
        self::assertNotNull($share->expiresAt);

        // Someone with no account.
        $client->restart();
        $crawler = $client->request('GET', '/n/' . $share->token);
        self::assertResponseIsSuccessful();
        // Locked: the password form, and not the note.
        self::assertCount(1, $crawler->filter('input[name="password"]'));
        self::assertCount(0, $crawler->filter('[data-notes--shared-grant-value]'));

        $client->request('POST', '/n/' . $share->token, ['password' => 'wrong']);
        self::assertResponseStatusCodeSame(422);

        $crawler = $client->request('POST', '/n/' . $share->token, ['password' => 'hunter2']);
        self::assertCount(1, $crawler->filter('textarea'));
        self::assertResponseIsSuccessful();
        $grant = (string) $crawler->filter('[data-notes--shared-grant-value]')->attr('data-notes--shared-grant-value');
        self::assertNotSame('', $grant);

        $client->request('POST', '/n/' . $share->token . '/save', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => 'Edited', 'body' => 'after']));
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/n/' . $share->token . '/save', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['grant' => $grant, 'title' => 'Edited', 'body' => 'after']));
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $saved = $em->find(Note::class, $note->id);
        self::assertSame('Edited', $saved?->title);
        self::assertSame('after', $saved?->body);

        $em->remove($saved);
        $em->flush();
    }
}
