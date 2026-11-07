<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Helper\AvatarStorage;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Tests\Domain\Helper\JpegOrientationTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The avatar is served small and cached for good.
 *
 * It was served whole and never cached: the controller asked for a week, and
 * Symfony's session listener replaced that with `max-age=0, must-revalidate`
 * on every response to a request that read the session, which this one does.
 * With no 304 either, a 1.7 MB photo was downloaded on every page load, and on
 * a server at the end of a home connection the first click after a reload
 * queued behind it.
 */
final class AvatarServingTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    public function testTheAvatarIsSmallAndCachedForGood(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (!$user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $avatars  = static::getContainer()->get(AvatarStorage::class);
        $entities = static::getContainer()->get(EntityManagerInterface::class);
        $before   = $user->avatar;

        $user->avatar = $avatars->storeContents((string) $user->id, 'me.jpg', JpegOrientationTest::jpeg(1600, 1200, 6));
        $entities->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/settings/profile/avatar/' . $user->avatar);

            self::assertResponseIsSuccessful();

            $response = $client->getResponse();
            $cache    = (string) $response->headers->get('Cache-Control');

            self::assertStringContainsString('private', $cache);
            self::assertStringContainsString('max-age=31536000', $cache);
            self::assertStringContainsString('immutable', $cache);
            self::assertStringNotContainsString('must-revalidate', $cache, 'the session listener still has the last word');
            self::assertSame('image/webp', $response->headers->get('Content-Type'));

            self::assertInstanceOf(BinaryFileResponse::class, $response);

            $file = $response->getFile();
            [$width, $height] = (array) getimagesize($file->getPathname());
            self::assertSame([192, 256], [$width, $height], 'the small copy, upright');

            $client->request('GET', '/settings/profile/avatar/' . $user->avatar, server: [
                'HTTP_IF_MODIFIED_SINCE' => (string) $response->headers->get('Last-Modified'),
            ]);

            self::assertResponseStatusCodeSame(304);
        } finally {
            $avatars->deleteAllFor((string) $user->id);
            $user = $entities->find(User::class, $user->id);
            $user->avatar = $before;
            $entities->flush();
        }
    }
}
