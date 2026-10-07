<?php

declare(strict_types=1);

namespace App\Command\Ai;

use App\Entity\Ai\AiFeature;
use App\Repository\User\UserRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\ClassificationCatchUp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The schedule's end of ClassificationCatchUp: every quarter of an hour, top
 * the backlog queue up with the newest mail the assistant has not seen.
 *
 * The shape of app:ai:index-new-mail, which does the same for the search
 * index, and deliberately so — a bounded sweep per mailbox, a keyset walk over
 * users, success when the feature is off.
 *
 * FREQUENT AND SMALL rather than nightly and large. The sweep posts nothing
 * while the queue still holds the last run's work, so the cadence is a ceiling
 * and the model host sets the pace; a quarter of an hour only decides how long
 * the queue may stand empty before it is noticed.
 */
#[AsCommand(
    name: 'app:ai:classify-backlog',
    description: 'Queue a bounded pass of old mail for the assistant to sort, once imports have finished',
)]
final class ClassifyBacklogCommand extends Command
{
    /**
     * Messages per mailbox per run.
     *
     * At a second or two a call this is a few minutes of a warm host — less
     * than the gap to the next run, so the queue drains and the host idles in
     * between rather than being held for the whole quarter hour.
     */
    private const string DEFAULT_LIMIT = '200';

    private const int USER_PAGE = 50;

    public function __construct(
        private readonly UserRepository        $users,
        private readonly AiAssistant           $ai,
        private readonly ClassificationCatchUp $catchUp,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Messages per mailbox', self::DEFAULT_LIMIT)
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'One mailbox instead of every mailbox');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Success, not failure, for the reason app:ai:index-new-mail gives:
        // this runs on every installation and almost none have the assistant
        // sorting mail.
        if (false === $this->ai->isEnabledFor(AiFeature::Categorise)) {
            $io->note('Sorting by assistant is off — nothing to classify.');

            return Command::SUCCESS;
        }

        if (false === $this->catchUp->mayRun()) {
            $io->note('The last pass is still being worked through, or the assistant is in use — nothing queued.');

            return Command::SUCCESS;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $email = $input->getOption('email');

        if (null !== $email) {
            $user = $this->users->findOneBy(['email' => (string) $email]);

            if (null === $user || null === $user->id) {
                $io->error('No such user.');

                return Command::FAILURE;
            }

            $queued = $this->catchUp->sweep($user, $limit);

            $io->success(sprintf('Queued %d message(s) from %s.', $queued, (string) $user->email));

            return Command::SUCCESS;
        }

        $lastId    = 0;
        $mailboxes = 0;
        $queued    = 0;

        // Keyset by id; see IndexNewMailCommand.
        while (true) {
            $page = $this->users->findBatchAfterId($lastId, self::USER_PAGE);

            if ([] === $page) {
                break;
            }

            foreach ($page as $user) {
                $lastId = (int) $user->id;

                $queued += $this->catchUp->sweep($user, $limit);
                $mailboxes++;
            }
        }

        $io->success(sprintf('Queued %d message(s) for the assistant, across %d mailbox(es).', $queued, $mailboxes));

        return Command::SUCCESS;
    }
}
