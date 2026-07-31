<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
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
        self::assertNotSame('', (string) $created->getPassword(), 'no password hash was stored');
        self::assertNotContains(User::ROLE_ADMIN, $created->getRoles());
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

        $client->request('GET', sprintf('/admin/users/%d/edit', $victim->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[type="password"]');
    }

    /** Nor may it be smuggled in by posting the field anyway. */
    public function testPostingAPasswordToTheEditFormChangesNothing(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();
        $before = (string) $victim->getPassword();

        $crawler = $client->request('GET', sprintf('/admin/users/%d/edit', $victim->getId()));

        $form = $crawler->filter('form')->form([
            'user_form[email]'     => (string) $victim->getEmail(),
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

        $reloaded = $this->reload((int) $victim->getId());

        self::assertSame($before, (string) $reloaded->getPassword(), 'an admin set another user\'s password');

        // Symfony rejects the whole submission rather than ignoring the unknown
        // field — allow_extra_fields is false by default — so the name does not
        // change either. Belt and braces: the field being unmapped would be
        // enough on its own, and this is the second lock.
        self::assertSame('Fixture', $reloaded->getNameFirst());
    }

    /** The edit that is allowed still works. */
    public function testAnAdministratorCanRenameAUser(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();

        $crawler = $client->request('GET', sprintf('/admin/users/%d/edit', $victim->getId()));

        $client->submit($crawler->filter('form')->form([
            'user_form[email]'     => (string) $victim->getEmail(),
            'user_form[nameFirst]' => 'Renamed',
            'user_form[nameLast]'  => 'Person',
        ]));

        self::assertSame('Renamed', $this->reload((int) $victim->getId())->getNameFirst());
    }

    /** Promotion is the one role change the panel offers. */
    public function testAnAdministratorCanPromoteAndDemoteSomeoneElse(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();
        $path   = sprintf('/admin/users/%d/edit', $victim->getId());

        $crawler = $client->request('GET', $path);
        $client->submit($crawler->filter('form')->form([
            'user_form[email]'     => (string) $victim->getEmail(),
            'user_form[nameFirst]' => 'Fixture',
            'user_form[nameLast]'  => 'Person',
            'user_form[isAdmin]'   => '1',
        ]));

        self::assertContains(User::ROLE_ADMIN, $this->reload((int) $victim->getId())->getRoles());

        // Explicitly unticked: DomCrawler carries over the state the page was
        // rendered with, and after the promotion above the box comes back
        // checked. Omitting the key would re-submit it as still checked.
        $crawler = $client->request('GET', $path);
        $form    = $crawler->filter('form')->form([
            'user_form[email]'     => (string) $victim->getEmail(),
            'user_form[nameFirst]' => 'Fixture',
            'user_form[nameLast]'  => 'Person',
        ]);
        $isAdmin = $form['user_form[isAdmin]'];
        self::assertInstanceOf(ChoiceFormField::class, $isAdmin);
        $isAdmin->untick();

        $client->submit($form);

        self::assertNotContains(User::ROLE_ADMIN, $this->reload((int) $victim->getId())->getRoles());
    }

    public function testAnAdministratorCannotRemoveTheirOwnAccount(): void
    {
        $client = $this->signInAsAdmin();
        $admin  = $this->users->findOneBy(['email' => $this->adminEmail]);

        $client->request('POST', sprintf('/admin/users/%d/delete', $admin->getId()), [
            '_token' => $this->token($client, 'admin-user-delete-' . $admin->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload((int) $admin->getId())->getDeletedAt());
    }

    public function testRemovingAUserIsASoftDeleteThatFreesTheAddress(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();
        $email  = (string) $victim->getEmail();
        $id     = (int) $victim->getId();

        $client->request('POST', sprintf('/admin/users/%d/delete', $id), [
            '_token' => $this->token($client, 'admin-user-delete-' . $id),
        ]);

        self::assertResponseIsSuccessful();

        // reload() asserts the row is still there, which is the hard-delete
        // check: a cascade would have taken it with the user.
        $reloaded = $this->reload($id);

        self::assertNotNull($reloaded->getDeletedAt());
        self::assertNotSame($email, $reloaded->getEmail(), 'the address was not freed for reuse');
        self::assertNull($this->users->findOneBy(['email' => $email]));
    }

    /** A forged or absent token must not remove anyone. */
    public function testRemovalNeedsACsrfToken(): void
    {
        $client = $this->signInAsAdmin();
        $victim = $this->seedUser();

        $client->request('POST', sprintf('/admin/users/%d/delete', $victim->getId()), ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload((int) $victim->getId())->getDeletedAt());
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

        $this->adminEmail = (string) $admin->getEmail();

        $client->loginUser($admin);

        return $client;
    }

    private function seedUser(): User
    {
        $user = new User();
        $user
            ->setEmail('admin-users-' . uniqid('', true) . '@example.test')
            ->setNameFirst('Fixture')
            ->setNameLast('Person')
            ->setRoles(['ROLE_USER'])
            ->setPassword('$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

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
