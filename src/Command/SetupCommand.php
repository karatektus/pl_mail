<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SetupCommand extends Command
{
    private const NAME = 'app:setup';

    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    private UserRepository $userRepository;


    public function __construct(EntityManagerInterface $documentManager, UserPasswordHasherInterface $passwordHasher, UserRepository $userRepository)
    {
        $this->entityManager = $documentManager;
        $this->passwordHasher = $passwordHasher;

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
        $user
            ->setEmail($eMail)
            ->setPassword($this->passwordHasher->hashPassword($user, $password))
            ->setNameFirst('Admin')
            ->setNameLast('Istrator')
            ->setRoles([User::ROLE_ADMIN]);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return 0;
    }


}