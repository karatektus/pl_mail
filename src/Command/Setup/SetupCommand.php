<?php

namespace App\Command\Setup;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Setup\FirstAdminInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SetupCommand extends Command
{
    private const NAME = 'app:setup';

    private FirstAdminInstaller $firstAdminInstaller;

    private UserRepository $userRepository;


    public function __construct(FirstAdminInstaller $firstAdminInstaller, UserRepository $userRepository)
    {
        $this->firstAdminInstaller = $firstAdminInstaller;

        $this->userRepository = $userRepository;

        parent::__construct(self::NAME);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $symfonyStyle->title('Setup');

        if (0 !== ($result = $this->firstUser($symfonyStyle))) {
            return $result;
        }

        return 0;
    }

    private function firstUser(SymfonyStyle $symfonyStyle): int
    {
        $count = $this->userRepository->countUndeleted();
        if (0 !== $count) {
            $symfonyStyle->success(sprintf('%d users already existing', $count));

            return 0;
        }

        $eMail = $symfonyStyle->ask('What\'s the first users email address?', $_ENV['APP_DEV_USER_EMAIL']);
        $password = $symfonyStyle->ask('What\'s the first users password?', $_ENV['APP_DEV_USER_PASSWORD']);

        $user = new User();
        $user->email = $eMail;
        $user->nameFirst = 'Admin';
        $user->nameLast = 'Istrator';

        // Same locked write as the /install page, so the terminal route and the
        // browser route cannot disagree about what "the first user" means.
        if (false === $this->firstAdminInstaller->install($user, $password)) {
            $symfonyStyle->warning('A user was created by something else while this command was running; nothing was changed.');

            return 0;
        }

        return 0;
    }


}