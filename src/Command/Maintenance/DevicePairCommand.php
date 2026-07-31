<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use App\Repository\User\UserRepository;
use App\Service\User\DevicePairingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Issues a device pairing code from the command line.
 *
 * The settings page is the normal way in. This exists for the case the web UI
 * cannot serve: a headless box where the admin is already on the shell, and
 * anyone locked out badly enough that reaching Settings is the problem rather
 * than the solution.
 *
 * Prints the pairing URI rather than the code alone, because that is what the
 * app consumes — as a scanned QR, or pasted, or tapped as a link.
 */
#[AsCommand(
    name: 'app:device:pair',
    description: 'Issue a short-lived pairing code so a device can enrol itself.',
)]
final class DevicePairCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DevicePairingService $pairing,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Which user the device will sign in as')
            ->addOption(
                'base-url',
                null,
                InputOption::VALUE_REQUIRED,
                'The address the device will reach this server on',
                'http://localhost',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $user = $this->users->findOneBy(['email' => $email]);

        if (null === $user) {
            $io->error(sprintf('No user with the address "%s".', $email));

            return Command::FAILURE;
        }

        ['code' => $code, 'expiresAt' => $expiresAt] = $this->pairing->issue($user);

        $io->success('Pairing code issued.');
        $io->writeln($this->pairing->pairingUri((string) $input->getOption('base-url'), $code));
        $io->note(sprintf(
            'Valid until %s, and only once.',
            $expiresAt->format('H:i:s'),
        ));

        return Command::SUCCESS;
    }
}
