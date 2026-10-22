<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Integration\Provider;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Integration\Integration;
use App\Repository\AddressBook\AddressBookRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gives the test user synced address books to save contacts into: one
 * Nextcloud connection with --books writable books and one read-only book
 * (a company directory, which "Save to" must never offer).
 *
 * The server is a name under the reserved `.test` domain, so nothing is ever
 * reached: a contact saved into one of these books waits to be pushed, which
 * is all the new-contact pages and their specs need to see. It also means a
 * stack that runs the sync worker will report the connection as failing —
 * expected, and why this is a fixture and not demo data.
 *
 * Idempotent: the connection it made last time, and its books, are removed
 * first. `--books=0` just removes them.
 */
#[AsCommand(
    name: 'app:test:seed-address-books',
    description: 'Give the test user a fake Nextcloud connection with synced address books (--books=N, 0 removes them)',
)]
final class SeedAddressBooksCommand extends Command
{
    use TargetsTestUser;

    private const string CONNECTION = 'Nextcloud (test)';
    private const string SERVER     = 'https://cloud.e2e.test';
    private const array NAMES       = ['Personal', 'Family', 'Work', 'Clients', 'Club', 'Neighbours', 'Archive'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly IntegrationRepository $integrations,
        private readonly AddressBookRepository $books,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();
        $this->addOption('books', null, InputOption::VALUE_REQUIRED, 'How many writable books (0 removes them)', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('Fixture commands do not run in prod.');

            return Command::FAILURE;
        }

        $email = $this->resolveUserEmail($input);
        $user  = $this->users->findOneBy(['email' => $email]);

        if (null === $user) {
            $io->error(sprintf('No user "%s".', $email));

            return Command::FAILURE;
        }

        $count = max(0, min(\count(self::NAMES), (int) $input->getOption('books')));

        foreach ($this->integrations->findBy(['usr' => $user, 'provider' => Provider::CardDav, 'name' => self::CONNECTION]) as $old) {
            foreach ($this->books->findBy(['integration' => $old]) as $book) {
                $this->em->remove($book);
            }
            $this->em->remove($old);
        }
        $this->em->flush();

        if (0 === $count) {
            $io->success(sprintf('Removed the test address books of "%s".', $email));

            return Command::SUCCESS;
        }

        $connection           = new Integration($user, Provider::CardDav, self::CONNECTION);
        $connection->baseUrl  = self::SERVER;
        $connection->username = 'e2e';
        $connection->secret   = 'not-a-real-password';
        $this->em->persist($connection);

        foreach (\array_slice(self::NAMES, 0, $count) as $name) {
            $this->em->persist(new AddressBook($user, $connection, sprintf('%s/remote.php/dav/addressbooks/users/e2e/%s/', self::SERVER, strtolower($name)), $name));
        }

        $directory             = new AddressBook($user, $connection, self::SERVER . '/remote.php/dav/addressbooks/system/directory/', 'Company directory');
        $directory->isReadOnly = true;
        $this->em->persist($directory);

        $this->em->flush();

        $io->success(sprintf('Gave "%s" %d writable address book(s) and a read-only one on %s.', $email, $count, self::SERVER));

        return Command::SUCCESS;
    }
}
