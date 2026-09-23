<?php

declare(strict_types=1);

namespace App\Tests\Security\TwoFactor;

use App\Entity\User\User;
use App\Service\User\TwoFactor\TwoFactorEnrolment;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The code throttle belongs to the account, not to the session.
 *
 * It used to be keyed on the session id, so signing in with the password again
 * — a new session — handed a stolen password another five guesses, as many
 * times as the attacker cared to log in. Driven through the real forms because
 * the key is decided by what survives between two logins.
 */
final class TwoFactorThrottleTest extends WebTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user = $this->enrolledUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testANewSessionForTheSameUserIsStillThrottled(): void
    {
        // The limit is five, spent across two sessions. Split three and two
        // because five wrong codes also trip the password form's own
        // login_throttling for this username and address, which would stop the
        // second login before the code form is reached — an attacker changes
        // address, so that is not the protection under test.
        $this->signInWithPassword();
        $this->wrongCodes(3);

        // Start over as the attacker would: a fresh session, the password again.
        $this->client->getCookieJar()->clear();
        $this->signInWithPassword();
        $this->wrongCodes(2);

        $this->client->request('POST', '/2fa_check', ['_auth_code' => '000000']);

        self::assertSame(429, $this->client->getResponse()->getStatusCode(), 'a new session reset the code throttle');
    }

    private function wrongCodes(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->client->request('POST', '/2fa_check', ['_auth_code' => '000000']);
            self::assertNotSame(429, $this->client->getResponse()->getStatusCode(), 'throttled before the limit');
        }
    }

    private function signInWithPassword(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token   = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->followRedirects(true);

        try {
            $this->client->request('POST', '/login', [
                'email'       => $this->user->email,
                'password'    => self::PASSWORD,
                '_csrf_token' => $token,
            ]);
        } finally {
            $this->client->followRedirects(false);
        }

        self::assertSame('/2fa', parse_url($this->client->getRequest()->getUri(), PHP_URL_PATH), 'expected the code prompt');
    }

    private function enrolledUser(): User
    {
        $user            = new User();
        $user->email     = 'throttle-'.bin2hex(random_bytes(6)).'@plmail.test';
        $user->nameFirst = 'Two';
        $user->nameLast  = 'Factor';
        $user->password  = static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, self::PASSWORD);

        $this->em->persist($user);
        $this->em->flush();

        $enrolment = static::getContainer()->get(TwoFactorEnrolment::class);
        $enrolment->begin($user);
        $enrolment->confirm($user, TOTP::create($user->totpSecret, 30, 'sha1', 6)->now());

        return $user;
    }
}
