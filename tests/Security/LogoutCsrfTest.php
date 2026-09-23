<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Logging out takes a CSRF token, so a GET cannot do it.
 *
 * Before enable_csrf, `GET /logout` signed you out — any page on the internet
 * could do that to a plMail user with an image tag. The user menu's control is
 * a POST form carrying csrf_token('logout'), and that one still works.
 */
final class LogoutCsrfTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $user            = new User();
        $user->email     = 'logout-'.bin2hex(random_bytes(6)).'@plmail.test';
        $user->nameFirst = 'Log';
        $user->nameLast  = 'Out';
        $user->password  = 'not-used';
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testAGetNoLongerLogsOut(): void
    {
        $this->client->request('GET', '/logout');

        $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful('a plain GET to /logout ended the session');
    }

    public function testTheUserMenusFormStillLogsOut(): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox');
        $form    = $crawler->filter('form[action="/logout"]')->form();

        $this->client->submit($form);

        $this->client->request('GET', '/mail/inbox');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
