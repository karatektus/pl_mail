<?php

declare(strict_types=1);

namespace App\Tests\Controller\Notes;

use App\Entity\Note\Note;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A picture on a note: uploaded from the editor, listed on the note, served
 * back as itself, and removed again — file and entry both.
 */
final class NoteMediaTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    /** A 1×1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testAPictureIsAddedServedAndRemoved(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $em          = static::getContainer()->get(EntityManagerInterface::class);
        $note        = new Note();
        $note->usr   = $user;
        $note->title = 'Pictures ' . bin2hex(random_bytes(3));
        $em->persist($note);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/notes/' . $note->id);
        $token   = (string) $crawler->filter('form[action="/notes/' . $note->id . '/close"] input[name="_token"]')->attr('value');

        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, base64_decode(self::PNG));

        $client->request('POST', '/notes/' . $note->id . '/media', ['kind' => 'image'], [
            'file' => new UploadedFile($path, 'dot.png', 'image/png', null, true),
        ], ['HTTP_X-CSRF-Token' => $token]);
        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $client->getInternalResponse()->getContent(), true);
        self::assertSame('image', $answer['media']['kind']);

        $client->request('GET', $answer['url']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');

        // Not a picture: refused.
        $text = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($text, 'hello');
        $client->request('POST', '/notes/' . $note->id . '/media', ['kind' => 'image'], [
            'file' => new UploadedFile($text, 'a.txt', 'text/plain', null, true),
        ], ['HTTP_X-CSRF-Token' => $token]);
        self::assertResponseStatusCodeSame(422);

        $client->request('POST', $answer['url'] . '/delete', [], [], ['HTTP_X-CSRF-Token' => $token]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $saved = $em->find(Note::class, $note->id);
        self::assertSame([], $saved?->media);

        $client->request('GET', $answer['url']);
        self::assertResponseStatusCodeSame(404);

        $em->remove($saved);
        $em->flush();
    }
}
