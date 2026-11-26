<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Infrastructure\Messaging\Message\QueueSenderPicturesMessage;
use App\Repository\User\User\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Looks up pictures for everyone who has written lately (#639), for one user
 * or for all of them.
 *
 * The same work the admin switch and the user's own switches start by
 * themselves (QueueSenderPicturesMessage); by hand for after a restore, or to
 * see it happen. It only queues: the lookups run on the maintenance worker,
 * and what is allowed is decided there exactly as for mail arriving.
 */
#[AsCommand(
    name: 'app:sender-pictures:backfill',
    description: 'Queue sender picture lookups for the people who wrote to a user (or every user) in the last 90 days',
)]
final class BackfillSenderPicturesCommand extends Command
{
    public function __construct(
        private readonly UserRepository      $users,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('user-id', InputArgument::OPTIONAL, 'Only this user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $userId = $input->getArgument('user-id');

        $users = null === $userId ? $this->users->findAll() : array_filter([$this->users->find((int) $userId)]);

        if ([] === $users) {
            $io->error('No such user.');

            return Command::FAILURE;
        }

        foreach ($users as $user) {
            if (null !== $user->id) {
                $this->bus->dispatch(new QueueSenderPicturesMessage($user->id));
            }
        }

        $io->text(sprintf('Queued the lookup for %d user(s).', count($users)));

        return Command::SUCCESS;
    }
}
