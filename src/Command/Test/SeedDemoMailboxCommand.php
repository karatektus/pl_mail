<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\User\UserRepository;
use App\Service\Demo\DemoMailbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A mailbox worth photographing.
 *
 * The README's tour is captured by tests/e2e/screenshots.spec.ts, which used to
 * need a demo installation nobody else had: the E2E fixtures seed "E2E Trash
 * Me", which is the right name for an assertion and the wrong one for a picture
 * in a readme. So the screenshots could only be retaken by the one person
 * holding that mailbox, and only they could tell whether they still matched the
 * app.
 *
 * The mailbox itself now lives in App\Service\Demo\DemoMailbox, which the
 * hosted demo provisions visitors with too. What stays here is the cleanup
 * around it — the part that is only ever right for a throwaway machine, and is
 * why this command refuses to run in prod.
 *
 * Not for prod, and it says so. Everything it writes belongs to one account it
 * owns outright and wipes on every run, so it cannot touch mail that was synced.
 */
#[AsCommand(
    name: 'app:test:seed-demo',
    description: 'Seed a believable demo mailbox for the README screenshots',
)]
final class SeedDemoMailboxCommand extends Command
{
    use TargetsTestUser;

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly UserRepository          $userRepository,
        private readonly AccountRepository       $accountRepository,
        private readonly LabelRepository         $labelRepository,
        private readonly DemoMailbox             $demoMailbox,
        #[Autowire('%kernel.environment%')]
        private readonly string                  $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-demo must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('User "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $accounts = $this->accountRepository->findBy(['usr' => $user]);
        $account  = $this->demoMailbox->account($user, $accounts);

        // The calendar is no longer cleared here: DemoMailbox::seedCalendar()
        // wipes and rewrites it, the same way seed() does the mailbox, so the
        // screenshot suite's own dialog-created events are cleaned up by the
        // thing that then puts a real week in their place. Two callers deleting
        // the same rows was harmless and said the opposite of what is true.

        // The demo mailbox ends up the only one, because the sidebar lists
        // every account and "E2E Mailbox" in a readme screenshot gives the
        // game away. Safe here and nowhere else: this command already refuses
        // to run in prod, and in dev or test the other accounts are fixtures.
        foreach ($accounts as $other) {
            if ($other->username !== DemoMailbox::ACCOUNT_USERNAME) {
                $this->entityManager->remove($other);
            }
        }

        $this->entityManager->flush();

        // Custom labels from other fixtures go too — "E2E Label" in the
        // sidebar of a readme screenshot is the same tell as "E2E Trash Me" in
        // the message list. System labels stay: they are this account's
        // folders, not decoration.
        foreach ($this->labelRepository->findBy(['usr' => $user, 'role' => null]) as $label) {
            if (false === in_array((string) $label->name, DemoMailbox::LABELS, true)) {
                $this->entityManager->remove($label);
            }
        }

        $this->entityManager->flush();

        $messages = $this->demoMailbox->seed($user, $account);
        $events   = $this->demoMailbox->seedCalendar($user);

        $io->success(sprintf(
            'Seeded %d demo threads, %d labels and %d calendar events for %s.',
            count($messages),
            count(DemoMailbox::LABELS),
            $events,
            $userEmail,
        ));

        return Command::SUCCESS;
    }
}
