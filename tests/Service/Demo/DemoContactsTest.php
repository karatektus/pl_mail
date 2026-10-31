<?php

declare(strict_types=1);

namespace App\Tests\Service\Demo;

use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;
use App\Service\Demo\DemoContacts;
use App\Service\Demo\DemoMailbox;
use App\Service\Demo\DemoMode;
use App\Service\Demo\DemoUserEraser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The address book a demo visitor is given.
 *
 * The same standing claims as DemoTemplatesTest: seeding twice gives the same
 * set rather than two of everything, and a visitor's contacts go when the
 * visitor does. And one of its own, that the book and the mailbox are about the
 * same people, because that is the point of seeding these addresses by hand.
 */
final class DemoContactsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        // An address the demo would have minted, so the eraser accepts it.
        $this->user            = new User();
        $this->user->email     = sprintf('%s%s@%s', DemoMode::USER_PREFIX, bin2hex(random_bytes(6)), DemoMode::USER_DOMAIN);
        $this->user->nameFirst = 'Demo';
        $this->user->nameLast  = 'Besucher';
        $this->user->roles     = [User::ROLE_USER];
        $this->user->password  = 'x';
        $this->user->timezone  = 'UTC';
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEveryContactHasOnePrimaryAddressAndThePhonesItWasGiven(): void
    {
        $seeded = static::getContainer()->get(DemoContacts::class)->seed($this->user);

        $contacts = $this->contacts();

        self::assertGreaterThan(0, $seeded);
        self::assertCount($seeded, $contacts);

        foreach ($contacts as $contact) {
            self::assertCount(
                1,
                $contact->emails->filter(static fn ($email): bool => $email->isPrimary),
                $contact->displayName . ' should have exactly one primary address',
            );
            self::assertLessThanOrEqual(1, $contact->phones->filter(static fn ($phone): bool => $phone->isPrimary)->count());
        }

        $marek = $this->named('Marek Nowak');

        self::assertSame('+493023125012', $marek->phones->first()->phone);
        self::assertSame('Berlin', $marek->addresses->first()->city);
        self::assertTrue($marek->isEditable(), 'a visitor should be able to try the editor on it');
    }

    /**
     * A card for someone in the mailbox has to carry the address they write
     * from. Both lists are written by hand, and a typo in either would leave
     * two strangers with one name.
     */
    public function testThePeopleInTheMailboxAreInTheBookAtTheSameAddress(): void
    {
        static::getContainer()->get(DemoContacts::class)->seed($this->user);

        $senders = array_column(DemoMailbox::THREADS, 'fromAddress', 'fromName');

        foreach (['Priya Raman', 'Mum', 'Jonas Weber', 'Sofia Marchetti', 'Marek Nowak', 'Steuerbüro Lang', 'Hotel Aare'] as $name) {
            self::assertSame($senders[$name], $this->named($name)->emails->first()->email);
        }
    }

    public function testSeedingTwiceGivesTheSameBookRatherThanTwoOfEverything(): void
    {
        $seeder = static::getContainer()->get(DemoContacts::class);

        $seeder->seed($this->user);
        $seeded = $seeder->seed($this->user);

        self::assertCount($seeded, $this->contacts());
    }

    public function testAVisitorsContactsGoWithTheVisitor(): void
    {
        $container = static::getContainer();
        $container->get(DemoContacts::class)->seed($this->user);

        $userId = $this->user->id;

        $container->get(DemoUserEraser::class)->erase($this->user);

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM contacts_contact WHERE usr_id = ?', [$userId]),
        );
    }

    /**
     * @return list<Contact>
     */
    private function contacts(): array
    {
        return static::getContainer()->get(ContactRepository::class)->findBy(['usr' => $this->user]);
    }

    private function named(string $name): Contact
    {
        foreach ($this->contacts() as $contact) {
            if ($name === $contact->displayName) {
                return $contact;
            }
        }

        self::fail('No contact called ' . $name);
    }
}
