<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A refused profile save comes back as the whole settings page.
 *
 * The form posts through Turbo Drive, which renders a failed submission as
 * the page. The controller used to answer with the profile partial alone, so a
 * blank name — or a picture a connected service could not hand over — replaced
 * the app with a bare card. The claim: 422, the app's frame around it, the
 * error on the form, and nothing stored.
 */
final class ProfileSaveRefusedTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    public function testARefusedSaveIsTheWholePageWithTheErrorOnIt(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (!$user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $before = $user->nameFirst;
        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings?section=profile');
        $form    = $crawler->filter('form[action$="/settings/profile"]')->form();
        $form['profile[nameFirst]'] = '';

        $crawler = $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#sidebar'), 'the app frame, not a bare card');
        self::assertCount(1, $crawler->filter('form[action$="/settings/profile"]'));
        self::assertSelectorExists('input[name="profile[nameFirst]"][aria-invalid="true"], form[action$="/settings/profile"] ul li');

        static::getContainer()->get('doctrine')->getManager()->clear();
        $stored = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        self::assertSame($before, $stored?->nameFirst);
    }
}
