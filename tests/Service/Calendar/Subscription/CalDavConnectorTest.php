<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Subscription;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Form\CalDavConnectType;
use App\Service\Calendar\Subscription\CalDavConnector;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * A stored mail password leaves the account it belongs to only when a person
 * says so.
 *
 * That is the claim, and it is a security one rather than a usability one. The
 * server address on this form is typed by the user and checked against nothing
 * — RFC 6764 bootstrapping will follow it wherever it leads — so a form that
 * reused a mailbox credential by default, or on a stale checkbox value, would
 * send that credential as HTTP Basic to a host nobody vetted, on a screen whose
 * apparent subject is calendars.
 *
 * Beside it, the smaller claim the browser suite turned up: the address the
 * form opens on is a guess, and it was guessed from Account::$email, which is a
 * free-text column routinely holding a display name. Every fixture mailbox in
 * this project is called "E2E Mailbox", so the field opened empty and the
 * "try their mail account's domain first" step silently did not happen.
 *
 * Against a real container, and through the real form: the rule being pinned
 * lives in the interaction between an unmapped checkbox, an unmapped entity
 * choice and an unmapped password field, and a hand-built double of a
 * FormInterface would be asserting the rule into existence rather than
 * observing it.
 */
final class CalDavConnectorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalDavConnector $connector;
    private FormFactoryInterface $forms;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->connector  = $container->get(CalDavConnector::class);
        $this->forms      = $container->get(FormFactoryInterface::class);

        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── The suggested address ─────────────────────────────────────────────

    /**
     * Account::$email is whatever the account form was given and is a label as
     * often as an address. Reading it alone is what left the field empty for
     * every mailbox named after itself.
     */
    public function testTheSuggestedAddressComesFromWhicheverFieldIsActuallyAnAddress(): void
    {
        $this->account(email: 'E2E Mailbox', username: 'mailbox@mail.example.test');

        self::assertSame('https://mail.example.test', $this->connector->suggestedAddress($this->user));
    }

    public function testAnAccountWithNoAddressAnywhereSuggestsNothing(): void
    {
        $this->account(email: 'Work', username: 'work');

        self::assertNull(
            $this->connector->suggestedAddress($this->user),
            'a field opened on "https://" is worse than one opened empty',
        );
    }

    /**
     * "root@localhost" is a real mailbox and https://localhost is not a server
     * anybody's calendars are on, so suggesting it would send the user round a
     * loop of connection failures.
     */
    public function testAMailboxOnABareHostnameIsNotSuggestedAsAServer(): void
    {
        $this->account(email: null, username: 'root@localhost');

        self::assertNull($this->connector->suggestedAddress($this->user));
    }

    // ── Borrowing a mail password ─────────────────────────────────────────

    public function testAStoredMailPasswordIsNotBorrowedUnlessTheBoxIsTicked(): void
    {
        $account = $this->account(email: 'mail@example.test', username: 'mail@example.test');

        $integration = $this->blankConnection();
        $form        = $this->submit($integration, [
            'reuseAccount' => (string) $account->id,
            'secret'       => 'typed-by-hand',
        ]);

        self::assertFalse(
            $this->connector->borrowMailPassword($integration, $form),
            'an unticked box must not lend anything, however complete the rest of the form is',
        );
        self::assertNull($integration->secret, 'the typed password is IntegrationConnector::save()\'s to apply');
    }

    public function testTickingTheBoxIsWhatSendsTheStoredPassword(): void
    {
        $account = $this->account(email: 'mail@example.test', username: 'mail@example.test');

        $integration = $this->blankConnection();
        $form        = $this->submit($integration, [
            'reuseMailPassword' => '1',
            'reuseAccount'      => (string) $account->id,
        ]);

        $this->connector->borrowMailPassword($integration, $form);

        self::assertSame('the-mailbox-password', $integration->secret);
    }

    /**
     * The id is a number in a form field. Honouring one that names somebody
     * else's account would hand their mail password to a server this user
     * typed the address of.
     *
     * The first guard is the choice list: it is built from this user's own
     * lending accounts, so a stranger's id is not a value the field accepts.
     */
    public function testAStrangersAccountIsNotEvenAValidChoice(): void
    {
        $stranger = $this->stranger();

        $integration = $this->blankConnection();
        $form        = $this->submit($integration, [
            'reuseMailPassword' => '1',
            'reuseAccount'      => (string) $stranger->id,
        ]);

        self::assertFalse($this->connector->borrowMailPassword($integration, $form));
        self::assertNotSame('the-mailbox-password', $integration->secret);
    }

    /**
     * And the second guard, which is the one that has to hold on its own: the
     * choice list is built by a caller, and a caller that one day builds it
     * from the wrong user — or from every account in the install — would
     * otherwise be enough to leak a credential. The account is re-checked
     * against the connection's owner here rather than trusted for having
     * survived the form.
     */
    public function testAStrangersAccountIsRefusedEvenWhenTheFormWouldAcceptIt(): void
    {
        $stranger = $this->stranger();

        $integration = $this->blankConnection();

        // Deliberately mis-built: the stranger IS an offered choice here.
        $form = $this->forms->create(CalDavConnectType::class, $integration, [
            'lending_accounts' => [$stranger],
            'csrf_protection'  => false,
        ]);

        $form->submit([
            'baseUrl'           => 'https://caldav.example.invalid/dav',
            'name'              => 'Example',
            'username'          => 'someone',
            'reuseMailPassword' => '1',
            'reuseAccount'      => (string) $stranger->id,
        ]);

        self::assertSame($stranger, $form->get('reuseAccount')->getData(), 'the form really did accept it');
        self::assertFalse($this->connector->borrowMailPassword($integration, $form));
        self::assertNull($integration->secret);
    }

    /**
     * An OAuth account holds a bearer token scoped to one provider's API, not a
     * password — offering it would be offering something no CalDAV server can
     * use, and would put a token in front of a host that should never see one.
     */
    public function testAnOauthAccountIsNotOfferedAsALender(): void
    {
        $this->account(email: 'imap@example.test', username: 'imap@example.test');
        $this->account(email: 'cloud@example.test', username: 'cloud@example.test', authType: 'oauth2');

        $lenders = $this->connector->lendingAccounts($this->user);

        self::assertCount(1, $lenders);
        self::assertSame('imap@example.test', $lenders[0]->username);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * Submitted rather than assembled: the rule under test reads three unmapped
     * fields, and only a real submit populates them.
     *
     * borrowMailPassword() rather than connect() at every call site below,
     * because connect() ends in IntegrationConnector, which probes the server
     * — and a test that reaches a network is a test that fails for reasons
     * that have nothing to do with the rule it names.
     *
     * @param array<string,string> $values
     */
    private function submit(Integration $integration, array $values): FormInterface
    {
        $form = $this->forms->create(CalDavConnectType::class, $integration, [
            'lending_accounts' => $this->connector->lendingAccounts($this->user),
            'csrf_protection'  => false,
        ]);

        $form->submit([
            'baseUrl'  => 'https://caldav.example.invalid/dav',
            'name'     => 'Example',
            'username' => 'someone',
            ...$values,
        ]);

        return $form;
    }

    private function blankConnection(): Integration
    {
        return new Integration($this->user, Provider::CalDav, 'Example');
    }

    private function account(?string $email, string $username, string $authType = 'password'): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->email          = $email;
        $account->username       = $username;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'the-mailbox-password';
        $account->authType       = $authType;
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function stranger(): Account
    {
        $other            = new User();
        $other->email     = 'other-' . uniqid('', true) . '@example.test';
        $other->nameFirst = 'Other';
        $other->nameLast  = 'Person';
        $other->roles     = ['ROLE_USER'];
        $other->password  = 'x';
        $this->em->persist($other);

        $account                 = new Account();
        $account->usr            = $other;
        $account->email          = 'stranger@example.test';
        $account->username       = 'stranger@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'the-mailbox-password';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'caldav-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Cal';
        $user->nameLast  = 'Dav';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        $this->user = $user;
    }
}
