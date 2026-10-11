<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\User\UserRepository;
use App\Service\Demo\DemoMailbox;
use App\Service\Demo\DemoTemplates;
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
 *
 * Not for a user who has a mailbox already, either, and it says that too. The
 * mailbox is the least of what this replaces: the user's calendar is wiped and
 * rewritten, their address book goes, and so does every custom label the demo
 * does not use. It used to remove their other accounts as well, so that "E2E
 * Mailbox" stayed out of the pictures — which made it a command that quietly
 * dismantled whichever fixture user it was pointed at, and it was pointed at
 * the one the whole regression suite signs in as. Their specs failed a day
 * later, on a badge count and an all-day chip, with nothing to connect the
 * failure to a screenshot retake. A user of the demo's own has no other
 * account to hide, so the removal is gone and the refusal is in its place.
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
        private readonly DemoTemplates           $demoTemplates,
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

        // Before anything is written, including the demo account itself: a
        // refusal that leaves half a demo behind is the pollution it refuses.
        foreach ($accounts as $other) {
            if (DemoMailbox::ACCOUNT_USERNAME !== $other->username) {
                $io->error(sprintf(
                    '%s already owns the mailbox "%s" — the demo replaces a user\'s calendar, contacts and '
                    .'labels, so it wants a user of its own. Seed one with app:test:seed-user and pass --email.',
                    $userEmail,
                    (string) $other->name,
                ));

                return Command::FAILURE;
            }
        }

        $account = $this->demoMailbox->account($user, $accounts);

        // The calendar is no longer cleared here: DemoMailbox::seedCalendar()
        // wipes and rewrites it, the same way seed() does the mailbox, so the
        // screenshot suite's own dialog-created events are cleaned up by the
        // thing that then puts a real week in their place. Two callers deleting
        // the same rows was harmless and said the opposite of what is true.

        // Custom labels that are not the demo's go — a filter built for one
        // retake's picture leaves its label behind for the next. System labels
        // stay: they are this account's folders, not decoration.
        foreach ($this->labelRepository->findBy(['usr' => $user, 'role' => null]) as $label) {
            if (false === in_array((string) $label->name, DemoMailbox::LABELS, true)) {
                $this->entityManager->remove($label);
            }
        }

        $this->entityManager->flush();

        $messages = $this->demoMailbox->seed($user, $account);
        $events   = $this->demoMailbox->seedCalendar($user);

        // The same templates a hosted demo's visitor gets, for the same reason
        // the mailbox is the same one: a screenshot of the picker should be a
        // picture of the demo.
        $templates = $this->demoTemplates->seed($user, $account);

        $io->success(sprintf(
            'Seeded %d demo threads, %d labels, %d calendar events and %d templates for %s.',
            count($messages),
            count(DemoMailbox::LABELS),
            $events,
            $templates,
            $userEmail,
        ));

        return Command::SUCCESS;
    }
}
