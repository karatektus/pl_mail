<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Entity\Label\Label;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\AccountRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\User\UserRepository;
use App\Service\Label\LabelResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seeds one visible custom label ("E2E Label") on the E2E mail account so the
 * "Label as" attach flow has something to attach. Idempotent; refuses prod.
 *
 * With `--folder` it seeds "E2E Folder" instead, a label that is a folder on
 * the account — what "Move to" offers on plain IMAP since #221 step 3, where
 * a label that is only a tag is put on with "Label as" and never moved to.
 *
 * With `--path=<a/b>` it seeds a folder at that path instead, named after its
 * last segment and nested under the label of the one before it, as a folder
 * sync would: for the folder tree under the account (#221). `--remove` takes
 * it away again, so a spec leaves the shared account's folder list as it found
 * it (account-folders.spec.ts expects it empty).
 *
 * Mirrors what LabelController::new persists: a Label with account + name
 * (+ colour), role left null (custom), everything else default. Fields are
 * assigned as public properties to match the entity's property-hook style.
 */
#[AsCommand(
    name: 'app:test:seed-label',
    description: 'Seed a custom label on the E2E mail account for the label-attach test',
)]
final class SeedTestLabelCommand extends Command
{
    use TargetsTestUser;

    private const string SEED_ACCOUNT_USERNAME = 'mailbox@e2e.test';
    private const string LABEL_NAME            = 'E2E Label';
    private const string FOLDER_NAME           = 'E2E Folder';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository         $userRepository,
        private readonly AccountRepository      $accountRepository,
        private readonly LabelRepository        $labelRepository,
        private readonly LabelResolver          $labelResolver,
        #[Autowire('%kernel.environment%')]
        private readonly string                 $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();
        $this->addOption('folder', null, InputOption::VALUE_NONE, 'Seed "' . self::FOLDER_NAME . '", a folder on the account, instead');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Seed a folder at this path ("/"-separated) instead');
        $this->addOption('remove', null, InputOption::VALUE_NONE, 'With --path: take that folder and its label away again');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-label must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('E2E user "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $account = $this->accountRepository->findOneBy([
            'usr'      => $user,
            'username' => self::SEED_ACCOUNT_USERNAME,
        ]);

        if (null === $account) {
            $io->error('E2E mail account not found — run app:test:seed-mail first.');

            return Command::FAILURE;
        }

        $path     = $input->getOption('path');
        $path     = is_string($path) && '' !== $path ? $path : null;
        $folder   = null !== $path || true === $input->getOption('folder');
        $segments = null !== $path ? explode('/', $path) : [];
        $name     = null !== $path ? (string) end($segments) : ($folder ? self::FOLDER_NAME : self::LABEL_NAME);
        $parent   = count($segments) > 1
            ? $this->labelRepository->findOneBy(['usr' => $user, 'name' => $segments[count($segments) - 2]])
            : null;
        $label    = $this->labelRepository->findOneBy([
            'usr'  => $user,
            'name' => $name,
        ]);

        if (null !== $path && true === $input->getOption('remove')) {
            $mailbox = null !== $label ? $label->bindingFor($account)?->mailbox : null;

            if (null !== $mailbox) {
                $this->entityManager->remove($mailbox);
            }

            if (null !== $label) {
                $this->entityManager->remove($label);
            }

            $this->entityManager->flush();
            $io->success(sprintf('Removed folder "%s".', $path));

            return Command::SUCCESS;
        }

        if (null === $label) {
            $label         = new Label();
            $label->usr    = $user;
            $label->name   = $name;
            $label->color  = $folder ? 'green' : 'blue';
            $label->parent = $parent;

            $this->entityManager->persist($label);
            $this->entityManager->flush();
        }

        // Labels are user-scoped; the binding is what puts it on this account.
        // Ensured on a re-seed too, so one after the account was recreated
        // leaves the label materialized on it.
        $binding = $this->labelResolver->binding($label, $account);

        if ($folder && null === $binding->mailbox) {
            $mailbox                = new Mailbox();
            $mailbox->account       = $account;
            $mailbox->name          = $name;
            $mailbox->fullPath      = $path ?? self::FOLDER_NAME;
            $mailbox->delimiter     = '/';
            $mailbox->isSyncEnabled = true;

            $this->entityManager->persist($mailbox);
            $binding->mailbox = $mailbox;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Seeded custom label "%s".', $name));

        return Command::SUCCESS;
    }
}
