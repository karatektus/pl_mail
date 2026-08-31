<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin user management — the only way to add the second person to an install.
 *
 * This controller existed as dead code: it referenced a form type that was
 * never written and two entity methods that do not exist, its templates were
 * absent, and nothing anywhere linked to it. Every one of its routes fatalled
 * on load. It was also unreachable, which is why nobody noticed.
 *
 * The tests below cover the feature, and then the three refusals that are the
 * point of it — an admin panel must not become a second way into a mailbox.
 */
final class AdminUserManagementTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private UserRepository $users;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** The regression that matters: it used to fatal on load. */
    public function testTheUserListRenders(): void
    {
        $client = $this->signInAsAdmin();

        $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#admin-users');
    }

    /** And it has to be reachable, which is what it never was. */
    public function testTheDashboardLinksToIt(): void
    {
        $client = $this->signInAsAdmin();

        $client->request('GET', '/admin?section=users');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href*="section=users"]');
        self::assertSelectorExists('turbo-frame#admin-users');
    }

    public function testAnAdministratorCanCreateAUserWhoCanThenSignIn(): void
    {
        $client = $this->signInAsAdmin();
        $email  = 'created-' . uniqid('', true) . '@example.test';

        $crawler = $client->request('GET', '/admin/users/create');
        self::assertResponseIsSuccessful();

        // Selected through the crawler rather than by button text: the submit
        // button carries only a translated label, no id or name.
        $client->submit($crawler->filter('form')->form([
            'user_form[email]'         => $email,
            'user_form[nameFirst]'     => 'New',
            'user_form[nameLast]'      => 'Person',
            'user_form[plainPassword]' => 'a-long-enough-password',
        ]));

        $created = $this->users->findOneBy(['email' => $email]);

        self::assertNotNull($created, 'the user was not created');
        self::assertNotContains(User::ROLE_ADMIN, $created->getRoles());

        // The hash verified against the plaintext, not merely asserted to be
        // non-empty. "A hash was stored" is what a bcrypt of the wrong string
        // also satisfies, and this test's name promises the user can sign in —
        // which is the claim the password hasher is the authority on.
        self::assertTrue(
            static::getContainer()
                ->get(UserPasswordHasherInterface::class)
                ->isPasswordValid($created, 'a-long-enough-password'),
            'the stored hash does not match the password the form was given',
        );
    }

    /**
     * An address that already exists used to be a 500.
     *
     * `email` is unique in the database and nothing looked before Postgres did,
     * so the one mistake an administrator is most likely to make on this form —
     * adding somebody who is already there — came back as an error page rather
     * than as a message under the field. Held by #[UniqueEntity] on the entity.
     */
    public function testAnAddressAlreadyInUseIsRefusedWithoutAnErrorPage(): void
    {
        $client   = $this->signInAsAdmin();
        $existing = $this->seedUser();

        $crawler = $client->request('GET', '/admin/users/create');

        $client->submit($crawler->filter('form')->form([
            'user_form[email]'         => (string) $existing->email,
            'user_form[nameFirst]'     => 'Second',
            'user_form[nameLast]'      => 'Claimant',
            'user_form[plainPassword]' => 'a-long-enough-password',
        ]));

        // 422 rather than 200, which AbstractController::render() sets when a
        // submitted form in the context is invalid. It is the code the modal
        // depends on: ui--modal closes the dialog on a successful
        // turbo:submit-end, so a 200 here would swallow the errors and read as
        // a silent save. What matters for this test is only that it is neither
        // a 500 nor a save.
        self::assertResponseStatusCodeSame(422);
        self::assertCount(
            1,
            $this->users->findBy(['email' => $existing->email]),
            'a second row was written for an address that is unique',
        );
    }

    /**
     * The length floor renders as a sentence, not as its own key.
     *
     * `admin.users.password_too_short` was defined in messages.*.yaml, and
     * Symfony resolves constraint messages in the `validators` domain — so the
     * form put the literal key on screen. validators.en.yaml carries a comment
     * about this having happened once before, with setup.install's copy of the
     * same message; this is the second time, and the assertion below is what
     * makes it the last.
     */
    public function testATooShortPasswordSaysSoInWords(): void
    {
        $client = $this->signInAsAdmin();

        $crawler = $client->request('GET', '/admin/users/create');

        $client->submit($crawler->filter('form')->form([
            'user_form[email]'         => 'short-' . uniqid('', true) . '@example.test',
            'user_form[nameFirst]'     => 'Too',
            'user_form[nameLast]'      => 'Short',
            'user_form[plainPassword]' => 'brief',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString(
            'admin.users.password_too_short',
            (string) $client->getResponse()->getContent(),
            'the constraint message rendered as its translation key',
        );
        self::assertSelectorTextContains('form', 'at least 12 characters');
    }

    /**
     * The security design of the form, asserted rather than trusted: an admin
     * who can set someone else's password can read their mail, which is the
     * same objection that keeps 2FA removal out of the web UI.
     */
    public function testTheEditFormOffersNoPasswordField(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();

        $client->request('GET', sprintf('/admin/users/%d/edit', $victim->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[type="password"]');
    }

    /** Nor may it be smuggled in by posting the field anyway. */
    public function testPostingAPasswordToTheEditFormChangesNothing(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();
        $before = (string) $victim->getPassword();

        $crawler = $client->request('GET', sprintf('/admin/users/%d/edit', $victim->id));

        $form = $crawler->filter('form')->form([
            'user_form[email]'     => (string) $victim->email,
            'user_form[nameFirst]' => 'Renamed',
            'user_form[nameLast]'  => 'Person',
        ]);

        // Smuggled in as a raw field, since the form does not render one.
        $form->getPhpValues();
        $client->request(
            'POST',
            $form->getUri(),
            array_merge_recursive($form->getPhpValues(), [
                'user_form' => ['plainPassword' => 'attacker-chosen-password'],
            ]),
        );

        $reloaded = $this->reload((int) $victim->id);

        self::assertSame($before, (string) $reloaded->getPassword(), 'an admin set another user\'s password');

        // Symfony rejects the whole submission rather than ignoring the unknown
        // field — allow_extra_fields is false by default — so the name does not
        // change either. Belt and braces: the field being unmapped would be
        // enough on its own, and this is the second lock.
        self::assertSame('Fixture', $reloaded->nameFirst);
    }

    /** The edit that is allowed still works. */
    public function testAnAdministratorCanRenameAUser(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();

        $crawler = $client->request('GET', sprintf('/admin/users/%d/edit', $victim->id));

        $client->submit($crawler->filter('form')->form([
            'user_form[email]'     => (string) $victim->email,
            'user_form[nameFirst]' => 'Renamed',
            'user_form[nameLast]'  => 'Person',
        ]));

        self::assertSame('Renamed', $this->reload((int) $victim->id)->nameFirst);
    }

    /** Promotion is the one role change the panel offers. */
    public function testAnAdministratorCanPromoteAndDemoteSomeoneElse(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();
        $path   = sprintf('/admin/users/%d/edit', $victim->id);

        $crawler = $client->request('GET', $path);
        $client->submit($crawler->filter('form')->form([
            'user_form[email]'     => (string) $victim->email,
            'user_form[nameFirst]' => 'Fixture',
            'user_form[nameLast]'  => 'Person',
            'user_form[isAdmin]'   => '1',
        ]));

        self::assertContains(User::ROLE_ADMIN, $this->reload((int) $victim->id)->getRoles());

        // Explicitly unticked: DomCrawler carries over the state the page was
        // rendered with, and after the promotion above the box comes back
        // checked. Omitting the key would re-submit it as still checked.
        $crawler = $client->request('GET', $path);
        $form    = $crawler->filter('form')->form([
            'user_form[email]'     => (string) $victim->email,
            'user_form[nameFirst]' => 'Fixture',
            'user_form[nameLast]'  => 'Person',
        ]);
        $isAdmin = $form['user_form[isAdmin]'];
        self::assertInstanceOf(ChoiceFormField::class, $isAdmin);
        $isAdmin->untick();

        $client->submit($form);

        self::assertNotContains(User::ROLE_ADMIN, $this->reload((int) $victim->id)->getRoles());
    }

    /**
     * A refused demotion has to say it was refused.
     *
     * It used to be a silent no-op: applyAdminRole() returned early, the modal
     * closed on a 200, the list redrew, and the tick was simply back the next
     * time anybody opened the form. Nothing distinguishes that from a save that
     * did not stick, so the honest reading of the screen was "the checkbox is
     * broken" — which is how it was reported.
     */
    public function testARefusedSelfDemotionSaysSoInsteadOfLookingLikeASave(): void
    {
        $client = $this->signInAsAdmin();
        $admin  = $this->users->findOneBy(['email' => $this->adminEmail]);

        // A second administrator, so the refusal under test is the self-demotion
        // one and not "you are the last one" wearing its message.
        $colleague = $this->seedUser();
        $colleague->addRole(User::ROLE_ADMIN);
        $this->em->flush();

        $path    = sprintf('/admin/users/%d/edit', $admin->id);
        $crawler = $client->request('GET', $path);
        $form    = $crawler->filter('form')->form([
            'user_form[email]'     => (string) $admin->email,
            'user_form[nameFirst]' => 'Renamed',
            'user_form[nameLast]'  => 'Person',
        ]);
        $isAdmin = $form['user_form[isAdmin]'];
        self::assertInstanceOf(ChoiceFormField::class, $isAdmin);
        $isAdmin->untick();

        $client->submit($form);

        $reloaded = $this->reload((int) $admin->id);

        self::assertContains(User::ROLE_ADMIN, $reloaded->getRoles(), 'an admin demoted themselves');
        self::assertStringContainsString(
            'cannot take administrator away from yourself',
            (string) $client->getResponse()->getContent(),
            'the refusal was silent',
        );

        // The rest of the submission still landed, which is why the refusal is
        // a toast rather than a rejected form: the rename was a separate,
        // perfectly legal edit that happened to travel with it.
        self::assertSame('Renamed', $reloaded->nameFirst);
    }

    // ── Search ────────────────────────────────────────────────────────────────

    /**
     * A one- or two-character search filters, rather than quietly returning
     * everybody.
     *
     * `createSearchQueryBuilder()` used to require three characters and then
     * fall through, so a short search produced the same list as no search at
     * all — with the term still sitting in the box claiming to have been
     * applied. A builder cannot say "too short" to its caller, so the floor
     * could only ever have been a lie.
     */
    #[DataProvider('shortSearches')]
    public function testAShortSearchStillFilters(string $term): void
    {
        $client = $this->signInAsAdmin();

        $wanted   = $this->seedUser();
        $wanted->nameFirst = 'Zzzq';
        $unwanted = $this->seedUser();
        $unwanted->nameFirst = 'Wwwp';
        $this->em->flush();

        $client->request('GET', '/admin/users?search=' . $term);

        $body = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString((string) $wanted->email, $body, 'the match was filtered out');
        self::assertStringNotContainsString(
            (string) $unwanted->email,
            $body,
            'a short search returned the unfiltered list',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function shortSearches(): iterable
    {
        yield 'one character'  => ['z'];
        yield 'two characters' => ['zz'];
    }

    // ── Switching an account off and back on ──────────────────────────────────

    public function testAnAdministratorCanSwitchAnAccountOffAndBackOn(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();
        $id     = (int) $victim->id;

        $client->request('POST', sprintf('/admin/users/%d/active', $id), [
            '_token' => $this->token($client, 'admin-user-active-' . $id),
        ]);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->reload($id)->deactivatedAt, 'the account was not switched off');

        $client->request('POST', sprintf('/admin/users/%d/active', $id), [
            '_token' => $this->token($client, 'admin-user-active-' . $id),
        ]);

        self::assertNull($this->reload($id)->deactivatedAt, 'the account could not be switched back on');
    }

    /**
     * Nothing else moves. The whole reason this exists beside removal is that
     * removal frees the address and overwrites the name, so a suspension that
     * did any of that would be a removal with a friendlier button.
     */
    public function testSwitchingOffLeavesEverythingElseAlone(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();
        $id     = (int) $victim->id;

        $email    = (string) $victim->email;
        $name     = $victim->nameFirst;
        $password = (string) $victim->getPassword();

        $client->request('POST', sprintf('/admin/users/%d/active', $id), [
            '_token' => $this->token($client, 'admin-user-active-' . $id),
        ]);

        $reloaded = $this->reload($id);

        self::assertSame($email, $reloaded->email, 'the address was freed by a suspension');
        self::assertSame($name, $reloaded->nameFirst, 'the name was tombstoned by a suspension');
        self::assertSame($password, (string) $reloaded->getPassword(), 'the password was destroyed by a suspension');
        self::assertNull($reloaded->deletedAt, 'a suspension soft-deleted the user');
    }

    /** Same lockout as removing yourself, and refused for the same reason. */
    public function testAnAdministratorCannotSwitchOffTheirOwnAccount(): void
    {
        $client = $this->signInAsAdmin();
        $admin  = $this->users->findOneBy(['email' => $this->adminEmail]);

        $client->request('POST', sprintf('/admin/users/%d/active', $admin->id), [
            '_token' => $this->token($client, 'admin-user-active-' . $admin->id),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload((int) $admin->id)->deactivatedAt);
    }

    /**
     * Two administrators, and switching one off is allowed.
     *
     * The pair of refusals assertSuspendable() carries mirror
     * assertRemovable()'s, and only the first of them is reachable through the
     * panel: an administrator holds ROLE_ADMIN themselves, so any OTHER
     * administrator they act on makes the count two. The last-administrator
     * clause is defence in depth against a caller that is not this one, and is
     * left unasserted rather than reached for by contortion — a test that has
     * to demote the acting user to set its scenario up is testing the scenario.
     */
    public function testAnAdministratorMayBeSwitchedOffWhileAnotherRemains(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();

        $victim->addRole(User::ROLE_ADMIN);
        $this->em->flush();

        $client->request('POST', sprintf('/admin/users/%d/active', $victim->id), [
            '_token' => $this->token($client, 'admin-user-active-' . $victim->id),
        ]);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->reload((int) $victim->id)->deactivatedAt);
    }

    /**
     * Switching an account back ON is never refused.
     *
     * Guarding both directions on the same rule would have made a suspended
     * administrator a state nothing could leave, and the account most likely to
     * need switching back on is exactly the one those guards would refuse. See
     * DefaultController::toggleActive(), where only the suspending direction
     * consults assertSuspendable().
     */
    public function testASuspendedAdministratorCanAlwaysBeSwitchedBackOn(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();
        $id     = (int) $victim->id;

        $victim->addRole(User::ROLE_ADMIN);
        $victim->deactivatedAt = new DateTimeImmutable();
        $this->em->flush();

        $client->request('POST', sprintf('/admin/users/%d/active', $id), [
            '_token' => $this->token($client, 'admin-user-active-' . $id),
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->reload($id)->deactivatedAt, 'a suspended administrator had no way back');
    }

    /** A forged or absent token must not switch anybody off. */
    public function testSwitchingOffNeedsACsrfToken(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();

        $client->request('POST', sprintf('/admin/users/%d/active', $victim->id), ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload((int) $victim->id)->deactivatedAt);
    }

    /** The list has to show it, or the state is invisible to the person who set it. */
    public function testTheListMarksASwitchedOffAccount(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();

        $victim->deactivatedAt = new DateTimeImmutable();
        $this->em->flush();

        $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Switched off', (string) $client->getResponse()->getContent());
    }

    public function testAnAdministratorCannotRemoveTheirOwnAccount(): void
    {
        $client = $this->signInAsAdmin();
        $admin  = $this->users->findOneBy(['email' => $this->adminEmail]);

        $client->request('POST', sprintf('/admin/users/%d/delete', $admin->id), [
            '_token' => $this->token($client, 'admin-user-delete-' . $admin->id),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload((int) $admin->id)->deletedAt);
    }

    public function testRemovingAUserIsASoftDeleteThatFreesTheAddress(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();
        $email  = (string) $victim->email;
        $id     = (int) $victim->id;

        $client->request('POST', sprintf('/admin/users/%d/delete', $id), [
            '_token' => $this->token($client, 'admin-user-delete-' . $id),
        ]);

        self::assertResponseIsSuccessful();

        // reload() asserts the row is still there, which is the hard-delete
        // check: a cascade would have taken it with the user.
        $reloaded = $this->reload($id);

        self::assertNotNull($reloaded->deletedAt);
        self::assertNotSame($email, $reloaded->email, 'the address was not freed for reuse');
        self::assertNull($this->users->findOneBy(['email' => $email]));
    }

    /** A forged or absent token must not remove anyone. */
    public function testRemovalNeedsACsrfToken(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();

        $client->request('POST', sprintf('/admin/users/%d/delete', $victim->id), ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload((int) $victim->id)->deletedAt);
    }

    /** Not an admin, not admitted. */
    public function testAnOrdinaryUserCannotReachIt(): void
    {
        $client = $this->boot();
        $client->loginUser($this->seedUser());

        $client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(403);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private string $adminEmail = '';

    /** Re-read from the container's current EntityManager; requests detach. */
    private function reload(int $id): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $user = $em->find(User::class, $id);

        self::assertNotNull($user, 'user vanished');

        return $user;
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->users      = $container->get(UserRepository::class);

        $this->connection->beginTransaction();

        return $client;
    }

    private function signInAsAdmin(): KernelBrowser
    {
        $client = $this->boot();

        $admin = $this->seedUser();
        $admin->addRole(User::ROLE_ADMIN);
        $this->em->flush();

        $this->adminEmail = (string) $admin->email;

        $client->loginUser($admin);

        return $client;
    }

    private function seedUser(): User
    {
        $user = new User();
        $user->email = 'admin-users-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Fixture';
        $user->nameLast = 'Person';
        $user->roles = ['ROLE_USER'];
        $user->password = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * A CSRF token for an action whose button the UI deliberately does not
     * render — which is the only way to test that the *controller* refuses it
     * too, rather than only that the button is missing.
     *
     * The GET first is load-bearing: the token storage reads the session off
     * the request stack, and outside a request cycle there is none.
     */
    private function token(KernelBrowser $client, string $id): string
    {
        // A GET first, so there is a session to mint against, then that same
        // session pushed back onto the request stack: CSRF tokens are stored
        // per-session and read off the current request, and by the time a
        // request has finished the stack is empty again.
        $client->request('GET', '/admin/users');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return (string) static::getContainer()
                ->get('security.csrf.token_manager')
                ->getToken($id)
                ->getValue();
        } finally {
            $stack->pop();
        }
    }
}
