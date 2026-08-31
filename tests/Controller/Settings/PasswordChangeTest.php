<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Changing your own password, which for a long time nothing on this install
 * could do.
 *
 * An administrator may not set somebody else's (UserFormType refuses, and
 * AdminUserManagementTest holds it), there is no reset-by-mail flow, and
 * `app:user:password` needs a shell on the host. So an account created for
 * somebody kept the password the administrator typed for it, and the only
 * people who could change one were the people who did not need to.
 *
 * Every assertion below goes through the password hasher rather than comparing
 * hash strings. "The column changed" is what hashing the WRONG string also
 * satisfies, and what this feature promises is that a specific new password
 * signs in — which the hasher is the only authority on.
 */
final class PasswordChangeTest extends WebTestCase
{
    private const string CURRENT = 'the-password-they-already-have';
    private const string CHOSEN  = 'a-long-enough-new-password';

    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The card exists, on the section it was asked for, with the field that is
     * the whole security design of it.
     */
    public function testTheSecurityPaneOffersAPasswordFormThatAsksForTheCurrentOne(): void
    {
        $client  = $this->signIn();
        $crawler = $client->request('GET', '/settings?section=security');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#settings-password');
        self::assertGreaterThan(
            0,
            $crawler->filter('#settings-password input[name="change_password[currentPassword]"]')->count(),
            'the form does not ask for the current password',
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('#settings-password input[name="change_password[plainPassword][second]"]')->count(),
            'the new password is not asked for twice',
        );

        // The floor is stated to the reader, and stated as a number.
        //
        // It reaches the page as a translation parameter so the string does not
        // carry a copy of User::PASSWORD_MIN_LENGTH in three catalogues — and
        // the app's form theme used to drop help parameters, so the field said
        // "at least %count% characters" on screen. Nothing else in the app
        // passes one, which is why nothing had noticed.
        $help = $crawler->filter('#settings-password')->text();

        self::assertStringNotContainsString('%count%', $help, 'the help text rendered its placeholder');
        self::assertStringContainsString('At least 12 characters', $help);
    }

    public function testChangingItMakesTheNewPasswordTheOneThatWorks(): void
    {
        $client = $this->signIn();

        $this->submit($client, self::CURRENT, self::CHOSEN, self::CHOSEN);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->passwordIs(self::CHOSEN), 'the new password does not sign in');
        self::assertFalse($this->passwordIs(self::CURRENT), 'the old password still signs in');
    }

    /**
     * The reply says it happened.
     *
     * A password form that succeeds looks exactly like one that lost the
     * submission: the fields come back empty either way and nothing else on the
     * page moves. The toast is the entire report, which makes it part of the
     * feature rather than polish on top of it.
     */
    public function testTheReplyIsAStreamThatSaysItHappened(): void
    {
        $client = $this->signIn();

        $this->submit($client, self::CURRENT, self::CHOSEN, self::CHOSEN);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(
            'text/vnd.turbo-stream.html',
            (string) $client->getResponse()->headers->get('Content-Type'),
            'the reply was not a Turbo Stream, so nothing on the page was updated',
        );
        self::assertStringContainsString('target="toast-region"', $body);
        self::assertStringContainsString('Password changed', $body);
        self::assertStringContainsString('target="settings-password"', $body);
    }

    /**
     * The lock that makes this endpoint safe on an unattended screen.
     *
     * Somebody who sits down at a signed-in browser can already read the mail;
     * what they must not be able to do is take the account. Without this check
     * a session is enough to change the password, which locks the owner out of
     * their own mailbox and out of every password reset that arrives in it.
     */
    public function testAWrongCurrentPasswordChangesNothing(): void
    {
        $client = $this->signIn();

        $this->submit($client, 'not-their-password', self::CHOSEN, self::CHOSEN);

        self::assertTrue($this->passwordIs(self::CURRENT), 'the password changed without proof of the old one');
        self::assertFalse($this->passwordIs(self::CHOSEN));
    }

    /**
     * A typo does not fail loudly on its own — it sets a password nobody knows,
     * to somebody who is then locked out the moment their session ends. Hence
     * the repeat, and hence this.
     */
    public function testTheTwoNewPasswordsHaveToMatch(): void
    {
        $client = $this->signIn();

        $this->submit($client, self::CURRENT, self::CHOSEN, self::CHOSEN . '-typo');

        self::assertTrue($this->passwordIs(self::CURRENT), 'a mismatched repeat still changed the password');
    }

    /**
     * The floor, and that it says so in a sentence.
     *
     * The assertion on the wording is the second half of the claim and not
     * decoration: validators.*.yaml carries a comment about a constraint
     * message put in the wrong domain rendering as its own key, and
     * AdminUserManagementTest holds the same line for the admin form. This is
     * the third message that could have gone in `messages` and the third test
     * that will not let it.
     */
    public function testAPasswordUnderTheFloorIsRefusedInWords(): void
    {
        $client = $this->signIn();

        $this->submit($client, self::CURRENT, 'short', 'short');

        self::assertTrue($this->passwordIs(self::CURRENT));

        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString(
            'settings.password.too_short',
            $body,
            'the constraint message rendered as its translation key',
        );
        self::assertStringContainsString('at least 12 characters', $body);
    }

    /**
     * The same floor as the admin create form, read from one constant rather
     * than typed twice. A test rather than a comment, because two literals that
     * agree today are exactly the thing that stops agreeing quietly.
     */
    public function testTheFloorIsTheOneTheAdminFormApplies(): void
    {
        self::assertSame(12, User::PASSWORD_MIN_LENGTH);
    }

    /** A forged or absent token must not change anybody's password. */
    public function testAPostWithoutTheFormsTokenChangesNothing(): void
    {
        $client = $this->signIn();

        $client->request('POST', '/settings/password', [
            'change_password' => [
                '_token'          => 'forged',
                'currentPassword' => self::CURRENT,
                'plainPassword'   => ['first' => self::CHOSEN, 'second' => self::CHOSEN],
            ],
        ]);

        self::assertTrue($this->passwordIs(self::CURRENT), 'a request with a forged CSRF token changed the password');
    }

    /** Nobody signed in, nothing to change. */
    public function testAnAnonymousPostIsSentToTheLoginForm(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->wire();

        $client->request('POST', '/settings/password');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    /**
     * Submits the real form off the real page, so the CSRF token and every
     * field name come from the markup rather than from this test's idea of
     * them. A hand-built POST would keep passing after a field was renamed.
     *
     * The Accept header is what Turbo sends for a `data-turbo-stream` form, and
     * it is load-bearing here: without it the endpoint takes its no-JavaScript
     * fallback and answers with a redirect, so the reply carries neither the
     * toast nor the field errors and every assertion about what the user is
     * TOLD would be asserting against a redirect page.
     */
    private function submit(KernelBrowser $client, string $current, string $first, string $second): void
    {
        $crawler = $client->request('GET', '/settings?section=security');

        $client->submit(
            $crawler->filter('#settings-password form')->form([
                'change_password[currentPassword]'        => $current,
                'change_password[plainPassword][first]'   => $first,
                'change_password[plainPassword][second]'  => $second,
            ]),
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'],
        );
    }

    /**
     * Asked of the hasher against a freshly read row — the request detached the
     * entity this test is holding, and the identity map would otherwise answer
     * with the hash from before the change.
     */
    private function passwordIs(string $candidate): bool
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $reloaded = $em->find(User::class, $this->user->id);

        self::assertNotNull($reloaded, 'the fixture user vanished');

        return static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->isPasswordValid($reloaded, $candidate);
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->wire();

        $this->user = $this->seedUser();

        $client->loginUser($this->user);

        return $client;
    }

    private function wire(): void
    {
        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();
    }

    private function seedUser(): User
    {
        $user = new User();
        $user->email = 'own-password-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Own';
        $user->nameLast = 'Password';
        $user->roles = [User::ROLE_USER];
        $user->password = static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, self::CURRENT);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
