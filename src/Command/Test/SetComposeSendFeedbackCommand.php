<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Puts the E2E user on one or other send behaviour.
 *
 * Send has two shapes, chosen per user by
 * User::SETTING_COMPOSE_SEND_FEEDBACK: the composer closes at once and a toast
 * carries the undo ("optimistic", the default), or the composer stays open and
 * its own Send pill is the cancel ("hold").
 *
 * Both are real behaviour with real specs, and neither can be reached from the
 * browser without walking through the settings page — which is somebody else's
 * spec, and slow. This is the seam, the same way every other `app:test:`
 * command is a seam for state the suite needs and the UI is a poor way to
 * reach.
 *
 * Idempotent. Refuses to run in prod.
 */
#[AsCommand(
    name: 'app:test:send-feedback',
    description: 'Set the compose send behaviour for the E2E user (optimistic|hold)',
)]
final class SetComposeSendFeedbackCommand extends Command
{
    use TargetsTestUser;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository         $userRepository,
        #[Autowire('%kernel.environment%')]
        private readonly string                 $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();
        $this->addArgument(
            'mode',
            InputArgument::REQUIRED,
            sprintf('"%s" or "%s"', User::SEND_FEEDBACK_OPTIMISTIC, User::SEND_FEEDBACK_HOLD),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('Fixture commands do not run in prod.');

            return Command::FAILURE;
        }

        $mode = (string) $input->getArgument('mode');

        if (false === in_array($mode, [User::SEND_FEEDBACK_OPTIMISTIC, User::SEND_FEEDBACK_HOLD], true)) {
            $io->error(sprintf('Unknown mode "%s".', $mode));

            return Command::FAILURE;
        }

        $email = $this->resolveUserEmail($input);
        $user  = $this->userRepository->findOneBy(['email' => $email]);

        if (null === $user) {
            $io->error(sprintf('No user "%s".', $email));

            return Command::FAILURE;
        }

        // null for the default, like every other key in this bag — so "reset it
        // to the default" and "store optimistic" cannot drift apart.
        $user->setSetting(
            User::SETTING_COMPOSE_SEND_FEEDBACK,
            User::SEND_FEEDBACK_HOLD === $mode ? User::SEND_FEEDBACK_HOLD : null,
        );

        $this->entityManager->flush();

        $io->success(sprintf('Send behaviour for "%s" is now "%s".', $email, $mode));

        return Command::SUCCESS;
    }
}
