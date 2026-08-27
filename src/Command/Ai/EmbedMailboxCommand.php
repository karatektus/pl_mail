<?php

declare(strict_types=1);

namespace App\Command\Ai;

use App\Entity\Ai\AiFeature;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\User\UserRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\EmbeddingBackfill;
use App\Service\Ai\EmbeddingStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Start embedding an existing mailbox.
 *
 * Semantic search only sees mail that has been embedded, and the two triggers
 * that keep up with new mail — the warm window after a search, and the nightly
 * `app:ai:index-new-mail` — are both bounded and both work newest-first. Neither
 * will ever reach the mail that was already in the mailbox when the feature was
 * switched on. That needs one pass, and on a large mailbox the pass is measured
 * in hours, so it is started deliberately rather than triggered by ticking a
 * box, and it is a queue job rather than something that runs inside this
 * command.
 *
 * THE DIVISION OF LABOUR, because the two look similar from outside and are
 * not: this one claims a state row, walks the WHOLE mailbox forwards by id for
 * as long as it takes, resumes after a restart, reports progress and can be
 * paused from the admin panel. `app:ai:index-new-mail` has a ceiling per
 * mailbox, keeps no state and tops up the top of the pile. A mailbox that is a
 * long way behind wants this one; nothing else will finish.
 *
 * Safe to run twice. The walk skips anything already embedded under the current
 * model, so a second run costs one query per chunk and stores nothing — and
 * after a model change nothing counts as embedded, which is how a re-embed is
 * asked for.
 */
#[AsCommand(
    name: 'app:ai:embed-mailbox',
    description: 'Queue a pass that embeds every message in a mailbox for semantic search',
)]
final class EmbedMailboxCommand extends Command
{
    public function __construct(
        private readonly UserRepository       $users,
        private readonly AiAssistant          $ai,
        private readonly AiSettingsRepository $settings,
        private readonly EmbeddingStore       $store,
        private readonly EmbeddingBackfill    $backfill,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Whose mailbox to embed')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every user on this installation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Refused rather than queued. A backfill against a switched-off feature
        // would dispatch a job that immediately returns, once, and report
        // success — which reads as "it is running" to whoever typed it.
        if (false === $this->ai->isEnabledFor(AiFeature::Search)) {
            $io->error('Semantic search is off, or no embedding model is configured. See Admin → AI.');

            return Command::FAILURE;
        }

        $email = $input->getOption('email');
        $all   = (bool) $input->getOption('all');

        if (null === $email && false === $all) {
            $io->error('Name a mailbox with --email, or pass --all.');

            return Command::FAILURE;
        }

        $users = true === $all
            ? $this->users->findAll()
            : array_filter([$this->users->findOneBy(['email' => (string) $email])]);

        if ([] === $users) {
            $io->error('No such user.');

            return Command::FAILURE;
        }

        $model = (string) $this->settings->currentOrDefault()->embeddingModel;

        $userIds = [];

        foreach ($users as $user) {
            if (null !== $user->id) {
                $userIds[] = (int) $user->id;

                $io->writeln(sprintf('Queueing a pass over <info>%s</info>.', (string) $user->email));
            }
        }

        // Through the service rather than dispatching the message here, and
        // that is load-bearing rather than tidy: the handler stops on its first
        // delivery unless the state row says a run is meant to be going, so a
        // command that posted its own message would queue a job that returned
        // immediately — and then say "Queued." to whoever typed it.
        $outcome = $this->backfill->start($userIds);

        if (EmbeddingBackfill::STARTED !== $outcome) {
            $io->error(match ($outcome) {
                EmbeddingBackfill::ALREADY_RUNNING => 'A pass is already running. Pause it in Admin → AI first, or wait for it to finish.',
                EmbeddingBackfill::SEARCH_OFF      => 'Semantic search is off, or no embedding model is configured. See Admin → AI.',
                // Named rather than left on the default, because it is now
                // reachable a second way: every mailbox on the list has search
                // switched off in its own settings, and "Nothing to do." would
                // send whoever typed this to look at the admin panel, where
                // everything is correctly switched on.
                EmbeddingBackfill::NO_MAILBOXES    => 'No mailbox to walk — every one named has search by meaning switched off in its own settings.',
                default                            => 'Nothing to do.',
            });

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Queued. %d messages are already embedded with %s; watch that number climb.',
            $this->store->countFor($model),
            $model,
        ));

        // Said plainly, because the alternative is somebody watching a queue
        // that is deliberately slow and concluding it has stalled.
        $io->note('This runs on the maintenance worker, one chunk at a time, and takes hours on a large mailbox. It is safe to interrupt and safe to run again.');

        // The panel is where this is actually watched, and it is also where the
        // pass can be paused — which is worth saying, because the deliberate
        // slowness is otherwise indistinguishable from a stall.
        $io->note('Admin → AI shows how far it has got, and can pause and resume it.');

        return Command::SUCCESS;
    }
}
