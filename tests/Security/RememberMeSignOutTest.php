<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Signing out ends "Keep me signed in" on this device, even for a request
 * that set off before the sign-out and arrives after it.
 *
 * Every page fires requests of its own in the background, and the person may
 * press Sign out while some are still on their way. Each carries the
 * remember-me cookie. With a cookie that only carries its own signature, the
 * one that reached the server after the sign-out was a valid credential and
 * signed the person straight back in — the sign-out did not hold. The token
 * is kept on the server now, and signing out deletes it.
 *
 * Played with the cookie itself: sign in with "Keep me signed in", keep a
 * copy of the cookie, sign out, and present only the copy.
 */
final class RememberMeSignOutTest extends WebTestCase
{
    private const string PASSWORD = 'a-password-to-stay-signed-in-with';

    private Connection $connection;

    private int $userId;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheCookieSignsInUntilTheSignOut(): void
    {
        $client = $this->boot();
        $cookie = $this->signIn($client);

        // The copy alone, as a request with no session would carry it.
        $this->onlyWith($client, $cookie);
        $client->request('GET', '/settings');

        self::assertResponseIsSuccessful('the remember-me cookie does not sign in at all, so the test below proves nothing');
    }

    public function testACookieThatArrivesAfterTheSignOutSignsNobodyIn(): void
    {
        $client = $this->boot();
        $cookie = $this->signIn($client);

        $crawler = $client->request('GET', '/settings');
        $token   = $crawler->filter('form[action="/logout"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/logout', ['_csrf_token' => $token], server: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->onlyWith($client, $cookie);
        $client->request('GET', '/settings');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * The other way a token ends, and the one a missing laptop needs: the
     * cookie is bound to the password it was issued under.
     */
    public function testAChangedPasswordEndsTheCookieToo(): void
    {
        $client = $this->boot();
        $cookie = $this->signIn($client);

        // Read back rather than reused: the requests above cleared the entity
        // manager, and a flush on the detached user would change nothing.
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $this->userId);
        self::assertNotNull($user);
        $user->password = static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'another-password-altogether');
        $em->flush();

        $this->onlyWith($client, $cookie);
        $client->request('GET', '/settings');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        return $client;
    }

    /** Signs in through the form with "Keep me signed in", and returns the cookie it set. */
    private function signIn(KernelBrowser $client): string
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email     = 'remembered-' . bin2hex(random_bytes(4)) . '@plmail.test';
        $user->nameFirst = 'Remembered';
        $user->nameLast  = 'Person';
        $user->roles     = ['ROLE_USER'];
        $user->password  = static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD);
        $em->persist($user);
        $em->flush();
        $this->userId = (int) $user->id;

        $form = $client->request('GET', '/login')->filter('form')->form([
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ]);
        $remember = $form['_remember_me'];
        self::assertInstanceOf(ChoiceFormField::class, $remember, 'the sign-in form has no "Keep me signed in" box');
        $remember->tick();
        $client->submit($form);

        $cookie = $client->getCookieJar()->get('REMEMBERME');

        self::assertNotNull($cookie, 'signing in with "Keep me signed in" set no cookie');

        return $cookie->getValue();
    }

    private function onlyWith(KernelBrowser $client, string $cookie): void
    {
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set(new Cookie('REMEMBERME', $cookie));
    }
}
