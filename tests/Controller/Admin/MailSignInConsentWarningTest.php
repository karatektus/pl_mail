<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Microsoft mail sign-in form warns an administrator that a tenant where
 * only administrators approve apps refuses every sign-in, mail included, until
 * plMail's new contacts permission is approved. Google's form has no such
 * case and no such warning.
 */
final class MailSignInConsentWarningTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    public function testOnlyTheMicrosoftFormWarnsAboutAdminApproval(): void
    {
        $client = static::createClient();
        $user   = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        $microsoft = $client->request('GET', '/admin/integrations/mail/microsoft/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $microsoft->filter('[data-admin-consent-warning]'));
        self::assertStringContainsString('Grant admin consent', $microsoft->filter('[data-admin-consent-warning]')->text(), 'it says what to do, not only what is wrong');

        $google = $client->request('GET', '/admin/integrations/mail/google/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $google->filter('[data-admin-consent-warning]'));
    }
}
