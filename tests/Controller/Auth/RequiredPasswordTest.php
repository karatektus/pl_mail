<?php

declare(strict_types=1);

namespace App\Tests\Controller\Auth;

use App\Domain\Enum\Crypto\UserKeyStatus;
use App\Entity\Crypto\UserKey;
use App\Entity\User\User;
use App\Security\PasswordKdf;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Somebody whose password an administrator chose picks their own before they
 * can do anything else — and nobody else is bothered.
 *
 * The flag is only worth anything if it cannot be clicked past, so most of
 * what is pinned here is what is NOT reachable while it is set, and that it
 * goes away the moment the person has chosen.
 */
final class RequiredPasswordTest extends WebTestCase
{
    private const string GIVEN  = 'the-one-the-admin-typed';
    private const string CHOSEN = 'the-one-they-picked-themselves';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testTheInboxSendsThemToChooseAPassword(): void
    {
        $this->signIn(required: true);

        // What a browser says about a page it is going to, as opposed to one
        // it is already on fetching something — see DocumentNavigation.
        $this->client->request('GET', '/mail/inbox', server: ['HTTP_ACCEPT' => 'text/html', 'HTTP_SEC_FETCH_DEST' => 'document']);

        self::assertResponseRedirects('/password/required');
    }

    /**
     * Whatever the page fetches in the background gets a bare refusal rather
     * than the form's HTML, which no image or live-update refresh can use.
     */
    public function testABackgroundFetchIsRefusedRatherThanRedirected(): void
    {
        $this->signIn(required: true);

        $this->client->request('GET', '/mail/inbox', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_SEC_FETCH_DEST' => 'empty']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testChoosingOneClearsTheFlagAndOpensTheInbox(): void
    {
        $this->signIn(required: true);

        $crawler = $this->client->request('GET', '/password/required');

        self::assertResponseIsSuccessful();

        $form       = $crawler->filter('form[name="required_password"]')->form();
        $salt       = base64_encode(random_bytes(PasswordKdf::SALT_BYTES));
        $iterations = (int) $form->get('required_password[new][iterations]')->getValue();

        $this->client->request('POST', '/password/required', ['required_password' => [
            '_token' => $form->get('required_password[_token]')->getValue(),
            'new'    => [
                'authHash'   => PasswordKdf::derive(self::CHOSEN, $salt, $iterations),
                'salt'       => $salt,
                'iterations' => (string) $iterations,
            ],
        ]]);

        self::assertResponseRedirects('/');

        $user = $this->reload();

        self::assertFalse($user->passwordChangeRequired, 'the flag survived the person choosing a password');
        self::assertSame($salt, $user->kdfSalt);
        self::assertTrue(
            static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, PasswordKdf::derive(self::CHOSEN, $salt, $iterations)),
            'the chosen password does not sign in',
        );

        $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
    }

    /**
     * A password this person did not choose must not be what their keys open
     * with: whatever hung off the one somebody else set is locked, not carried
     * over.
     */
    public function testChoosingOneLocksKeysThatHungOffThePasswordSomebodyElseChose(): void
    {
        $this->signIn(required: true);

        $userKey = new UserKey($this->user, 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48)));
        $this->em->persist($userKey);
        $this->em->flush();

        $crawler    = $this->client->request('GET', '/password/required');
        $form       = $crawler->filter('form[name="required_password"]')->form();
        $salt       = base64_encode(random_bytes(PasswordKdf::SALT_BYTES));
        $iterations = (int) $form->get('required_password[new][iterations]')->getValue();

        $this->client->request('POST', '/password/required', ['required_password' => [
            '_token' => $form->get('required_password[_token]')->getValue(),
            'new'    => [
                'authHash'   => PasswordKdf::derive(self::CHOSEN, $salt, $iterations),
                'salt'       => $salt,
                'iterations' => (string) $iterations,
            ],
        ]]);

        $this->em->clear();

        self::assertSame(UserKeyStatus::Locked, $this->em->find(UserKey::class, $userKey->id)?->status);
    }

    /** Signing out stays possible: the form is not a trap. */
    public function testSigningOutIsNotBlocked(): void
    {
        $this->signIn(required: true);

        $this->client->request('POST', '/logout');

        self::assertNotSame('/password/required', $this->client->getResponse()->headers->get('Location'));
    }

    /** Everybody else never sees the page. */
    public function testWithoutTheFlagThePageSendsThemOn(): void
    {
        $this->signIn(required: false);

        $this->client->request('GET', '/password/required');

        self::assertResponseRedirects('/');

        $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
    }

    /**
     * An administrator creating somebody chose their password, so the account
     * starts with the flag set.
     */
    public function testAnAccountAnAdministratorCreatesStartsFlagged(): void
    {
        $admin = $this->seedUser(required: false, roles: [User::ROLE_USER, User::ROLE_ADMIN]);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/create');
        $email   = 'created-' . bin2hex(random_bytes(6)) . '@plmail.test';

        $this->client->submit($crawler->filter('form')->form([
            'user_form[email]'         => $email,
            'user_form[nameFirst]'     => 'New',
            'user_form[nameLast]'      => 'Person',
            'user_form[plainPassword]' => self::GIVEN,
        ]));

        $this->em->clear();
        $created = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        self::assertNotNull($created, 'the fixture could not create a user through the admin form');
        self::assertTrue($created->passwordChangeRequired);
        self::assertFalse($created->hasClientDerivedPassword());
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function signIn(bool $required): void
    {
        $this->user = $this->seedUser($required);
        $this->client->loginUser($this->user);
    }

    private function reload(): User
    {
        $this->em->clear();

        $user = $this->em->find(User::class, $this->user->id);

        self::assertNotNull($user, 'the fixture user vanished');

        return $user;
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(bool $required, array $roles = [User::ROLE_USER]): User
    {
        $user = new User();
        $user->email = 'required-' . bin2hex(random_bytes(6)) . '@plmail.test';
        $user->nameFirst = 'Required';
        $user->nameLast = 'Password';
        $user->roles = $roles;
        $user->useServerHashedPassword(
            static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::GIVEN),
        );
        $user->passwordChangeRequired = $required;

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
