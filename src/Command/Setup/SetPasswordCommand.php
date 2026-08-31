<?php

declare(strict_types=1);

namespace App\Command\Setup;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The way back in after forgetting the password.
 *
 * This is the command the rest of the application had already been promising.
 * UserFormType's docblock says "someone locked out is recovered from the
 * console"; the admin panel says it twice more on screen — under the initial
 * password field, and in the footnote below the user list. None of it was true:
 * there was no such command, plMail has no forgotten-password mail flow, and
 * nothing anywhere lets an existing account change its own password either. The
 * only recovery was an UPDATE against the `user` table with a hash produced by
 * hand, which is the thing DisableTwoFactorCommand exists to stop people doing.
 *
 * A console command rather than a button in the admin panel, for the reason
 * UserFormType gives at length: an administrator who can set another user's
 * password can sign in as them and read their mail, so an admin session would
 * become a second way into every mailbox on the install. Shell access to the
 * host already implies database access, so this grants nothing that was not
 * available with more patience — and that difference in bar is the point.
 *
 * It does not touch the second factor. Somebody who has lost both needs this
 * and `app:user:2fa-disable`, and having to ask for the second one separately
 * is deliberate: they are different losses, and the confirmation prompt on the
 * 2FA command should be answered on its own terms rather than swept along.
 */
#[AsCommand(
    name: 'app:user:password',
    description: 'Set a new password for a user who can no longer sign in',
)]
final class SetPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository              $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface      $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the user to let back in')
            ->addOption(
                'password',
                null,
                InputOption::VALUE_REQUIRED,
                'The new password, for scripted use. Prompted for, hidden and twice, when omitted',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (null === $user) {
            $io->error(sprintf('No user found for email "%s".', $email));

            return Command::FAILURE;
        }

        // A removed user keeps their rows and loses their password, which is
        // what makes the account unable to authenticate. Giving one a working
        // hash would quietly undo somebody's decision to remove them — and it
        // is reachable, because the tombstone address the admin panel writes is
        // a real value somebody can pass here.
        if (true === $user->isDeleted()) {
            $io->error(sprintf('%s was removed. Restoring a removed user is not something this command does.', $email));

            return Command::FAILURE;
        }

        $plainPassword = $this->readPassword($io, $input);

        if (null === $plainPassword) {
            $io->comment('Nothing was changed.');

            return Command::FAILURE;
        }

        $user->password = $this->passwordHasher->hashPassword($user, $plainPassword);

        $this->em->flush();

        $io->success(sprintf('%s can sign in with the new password.', $email));

        // Said out loud because the command cannot do it and the operator has
        // to know the account is not fully open yet — a password on its own
        // does not get past an enrolled second factor.
        if (true === $user->isTotpAuthenticationEnabled()) {
            $io->comment('Two-factor authentication is still on for this account. If that is the other half of what they lost, see `app:user:2fa-disable`.');
        }

        return Command::SUCCESS;
    }

    /**
     * Null means "do not change anything" — an empty answer, a mismatch, or a
     * password under the floor.
     *
     * Asked twice rather than once because there is no way to check the result:
     * a typo here does not fail, it sets a password nobody knows, and the
     * person it was meant for is already locked out.
     */
    private function readPassword(SymfonyStyle $io, InputInterface $input): ?string
    {
        $supplied = $input->getOption('password');

        if (true === is_string($supplied) && '' !== $supplied) {
            return $this->accept($io, $supplied);
        }

        $first = (string) $io->askHidden('New password');

        if ('' === $first) {
            $io->error('No password given.');

            return null;
        }

        if ($first !== (string) $io->askHidden('Repeat it')) {
            $io->error('The two passwords do not match.');

            return null;
        }

        return $this->accept($io, $first);
    }

    private function accept(SymfonyStyle $io, #[SensitiveParameter] string $plainPassword): ?string
    {
        // mb_strlen, not strlen: the floor is a count of characters the person
        // has to type, and a passphrase with an umlaut in it is not two
        // characters longer for having one.
        if (User::PASSWORD_MIN_LENGTH > mb_strlen($plainPassword)) {
            $io->error(sprintf('Use at least %d characters — this password is being chosen for someone else.', User::PASSWORD_MIN_LENGTH));

            return null;
        }

        return $plainPassword;
    }
}
