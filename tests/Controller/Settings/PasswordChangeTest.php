<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\User\User;
use App\Security\PasswordKdf;
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
 *
 * Since passwords are stretched in the browser, neither password is posted:
 * the form carries the proof of the current one and the auth hash of the new
 * one, and this test computes both with PasswordKdf::derive(), which is pinned
 * to the browser's arithmetic by PasswordKdfTest. What the browser alone can
 * check — the floor, and that the two boxes agree — is asserted here only as
 * far as the server's part goes: that it tells the browser what to check.
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
            $crawler->filter('#settings-password input[name="change_password[new][password][second]"]')->count(),
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

        $this->submit($client, self::CURRENT, $this->stretched(self::CHOSEN, $salt = $this->salt()), $salt);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->passwordIs(PasswordKdf::derive(self::CHOSEN, $salt, $this->iterations())), 'the new password does not sign in');
        self::assertFalse($this->passwordIs(self::CURRENT), 'the old password still signs in');
    }

    /**
     * The new password is stored as the browser will next present it: with
     * the salt it was stretched with, so the prelogin can hand that salt out.
     * Changing it is also what moves an account still on a hash of the
     * password itself over to a stretched one.
     */
    public function testTheSaltItWasStretchedWithIsKeptBesideIt(): void
    {
        $client = $this->signIn();
        $salt   = $this->salt();

        $this->submit($client, self::CURRENT, $this->stretched(self::CHOSEN, $salt), $salt);

        $reloaded = $this->reloaded();

        self::assertTrue($reloaded->hasClientDerivedPassword(), 'the account is still on a hash of the password itself');
        self::assertSame($salt, $reloaded->kdfSalt);
        self::assertSame($this->iterations(), $reloaded->kdfIterations);
    }

    /**
     * An account already on a stretched password proves the current one with
     * the auth hash, stretched with its own salt — the plain password is no
     * longer something its stored hash can be checked against.
     */
    public function testAStretchedAccountProvesTheCurrentPasswordWithItsAuthHash(): void
    {
        $client = $this->signIn(stretched: true);
        $proof  = PasswordKdf::derive(self::CURRENT, (string) $this->user->kdfSalt, (int) $this->user->kdfIterations);
        $salt   = $this->salt();

        $this->submit($client, self::CURRENT, $this->stretched(self::CHOSEN, $salt), $salt, proof: $proof);

        self::assertTrue($this->passwordIs(PasswordKdf::derive(self::CHOSEN, $salt, $this->iterations())));

        // And the plain password is not accepted as proof in its place.
        $other = $this->salt();

        $this->submit($client, self::CHOSEN, $this->stretched('yet-another-new-password', $other), $other);

        self::assertTrue($this->passwordIs(PasswordKdf::derive(self::CHOSEN, $salt, $this->iterations())), 'a plain password was taken as proof for a stretched account');
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
        $salt   = $this->salt();

        $this->submit($client, self::CURRENT, $this->stretched(self::CHOSEN, $salt), $salt);

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
        $salt   = $this->salt();

        $this->submit($client, 'not-their-password', $this->stretched(self::CHOSEN, $salt), $salt);

        self::assertTrue($this->passwordIs(self::CURRENT), 'the password changed without proof of the old one');
        self::assertFalse($this->reloaded()->hasClientDerivedPassword());
    }

    /**
     * A browser without JavaScript posts no auth hash, and is told why in a
     * sentence rather than with a row of blank-field errors.
     */
    public function testABrowserThatStretchedNothingIsToldItNeedsScript(): void
    {
        $client = $this->signIn();

        $this->submit($client, self::CURRENT, '', '');

        self::assertTrue($this->passwordIs(self::CURRENT));
        self::assertStringContainsString('needs JavaScript', (string) $client->getResponse()->getContent());
    }

    /**
     * Fewer iterations is a weaker hash for whoever steals the table, and the
     * person choosing it is not the one at risk — so it is the server's number,
     * checked, and not the browser's.
     */
    public function testAnIterationCountOtherThanTheConfiguredOneIsRefused(): void
    {
        $client = $this->signIn();
        $salt   = $this->salt();

        $this->submit($client, self::CURRENT, PasswordKdf::derive(self::CHOSEN, $salt, 1), $salt, iterations: 1);

        self::assertTrue($this->passwordIs(self::CURRENT), 'a password stretched once was accepted');
    }

    /**
     * The floor, and that the browser is told it in a sentence.
     *
     * The server no longer sees the password, so the browser enforces the
     * floor — with a message it gets from the page. The assertion on the
     * wording is the half that can still fail here: validators.*.yaml carries
     * a comment about a constraint message put in the wrong domain rendering as
     * its own key, and this one is now read from that domain by a template.
     */
    public function testTheBrowserIsToldTheFloorInWords(): void
    {
        $client  = $this->signIn();
        $crawler = $client->request('GET', '/settings?section=security');
        $form    = $crawler->filter('#settings-password form');

        self::assertSame('12', $form->attr('data-auth--password-form-min-length-value'));
        self::assertSame('Use at least 12 characters.', $form->attr('data-auth--password-form-too-short-message-value'));
        self::assertStringNotContainsString('settings.password', (string) $form->attr('data-auth--password-form-mismatch-message-value'));
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
        $salt   = $this->salt();

        $client->request('POST', '/settings/password', [
            'change_password' => [
                '_token'       => 'forged',
                'currentProof' => self::CURRENT,
                'new'          => [
                    'authHash'   => $this->stretched(self::CHOSEN, $salt),
                    'salt'       => $salt,
                    'iterations' => (string) $this->iterations(),
                ],
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
     * What it fills is what password_form_controller.js fills: the proof of
     * the current password — the password itself for an account still on a
     * hash of it, unless a proof is given — and the stretched new one. The
     * boxes a person types into are left out, as the browser leaves them out.
     *
     * The Accept header is what Turbo sends for a `data-turbo-stream` form, and
     * it is load-bearing here: without it the endpoint takes its no-JavaScript
     * fallback and answers with a redirect, so the reply carries neither the
     * toast nor the field errors and every assertion about what the user is
     * TOLD would be asserting against a redirect page.
     */
    private function submit(
        KernelBrowser $client,
        string $current,
        string $authHash,
        string $salt,
        ?string $proof = null,
        ?int $iterations = null,
    ): void {
        $crawler = $client->request('GET', '/settings?section=security');
        $form    = $crawler->filter('#settings-password form')->form();

        $client->request(
            'POST',
            (string) $form->getUri(),
            ['change_password' => [
                '_token'       => $form->get('change_password[_token]')->getValue(),
                'currentProof' => $proof ?? $current,
                'new'          => [
                    'authHash'   => $authHash,
                    'salt'       => $salt,
                    'iterations' => (string) ($iterations ?? $form->get('change_password[new][iterations]')->getValue()),
                ],
            ]],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'],
        );
    }

    private function stretched(string $password, string $salt): string
    {
        return PasswordKdf::derive($password, $salt, $this->iterations());
    }

    private function salt(): string
    {
        return base64_encode(random_bytes(PasswordKdf::SALT_BYTES));
    }

    private function iterations(): int
    {
        return static::getContainer()->get(PasswordKdf::class)->iterations;
    }

    /**
     * Asked of the hasher against a freshly read row — the request detached the
     * entity this test is holding, and the identity map would otherwise answer
     * with the hash from before the change.
     */
    private function passwordIs(string $candidate): bool
    {
        return static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->isPasswordValid($this->reloaded(), $candidate);
    }

    private function reloaded(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $reloaded = $em->find(User::class, $this->user->id);

        self::assertNotNull($reloaded, 'the fixture user vanished');

        return $reloaded;
    }

    private function signIn(bool $stretched = false): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->wire();

        $this->user = $this->seedUser($stretched);

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

    private function seedUser(bool $stretched): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->email = 'own-password-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Own';
        $user->nameLast = 'Password';
        $user->roles = [User::ROLE_USER];

        if (true === $stretched) {
            $salt = $this->salt();

            $user->useClientDerivedPassword(
                $hasher->hashPassword($user, PasswordKdf::derive(self::CURRENT, $salt, $this->iterations())),
                $salt,
                $this->iterations(),
            );
        } else {
            $user->useServerHashedPassword($hasher->hashPassword($user, self::CURRENT));
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
