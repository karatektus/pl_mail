<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Infrastructure\Messaging\Message\ResolveSenderPictureMessage;
use App\Repository\Mail\SenderPicture\SenderPictureRepository;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use App\Service\Mail\SenderPicture\SenderPictureStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The daily round for sender pictures (#639): ask again about answers that
 * have gone stale, forget senders who stopped writing, and delete the files
 * nothing names any more.
 *
 * **Only senders seen in the last ninety days are re-asked.** A picture for
 * somebody who wrote once three years ago is not worth a monthly request to
 * Gravatar on their behalf; their row stays as it is until it is pruned, and
 * if they write again the ingest step claims it then.
 *
 * **Pruned after half a year unseen**, which takes their files with them
 * unless another row shares the file.
 *
 * At most a few hundred lookups per run, newest senders first, so an install
 * that just switched lookups on — and so has every row due on the same day a
 * month later — spreads the re-checks over a few days instead of firing them
 * all at once at strangers' servers.
 */
#[AsCommand(
    name: 'app:sender-pictures:refresh',
    description: 'Re-check stale sender pictures, forget senders not seen in six months, delete unused files',
)]
final class RefreshSenderPicturesCommand extends Command
{
    private const int RECHECK_SEEN_WITHIN_DAYS = 90;

    private const int PRUNE_UNSEEN_DAYS = 180;

    private const int DEFAULT_LIMIT = 500;

    public function __construct(
        private readonly SenderPictureRepository         $pictures,
        private readonly SenderPictureSettingsRepository $settings,
        private readonly SenderPictureStore              $store,
        private readonly MessageBusInterface             $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Most lookups to queue in this run', (string) self::DEFAULT_LIMIT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        // Pruning and file cleanup run whether or not lookups are on: an
        // administrator who switched them off still wants the table and the
        // disk to shrink back, not to be frozen as they were.
        $queued = 0;

        if (true === $this->settings->lookupsEnabled()) {
            $due = $this->pictures->claimDueForRefresh(
                new \DateTimeImmutable(sprintf('-%d days', self::RECHECK_SEEN_WITHIN_DAYS)),
                $limit,
            );

            foreach ($due as $row) {
                $this->bus->dispatch(new ResolveSenderPictureMessage($row['kind']->value, $row['subject']));
                ++$queued;
            }
        }

        $pruned  = $this->pictures->pruneNotSeenSince(new \DateTimeImmutable(sprintf('-%d days', self::PRUNE_UNSEEN_DAYS)));
        $deleted = $this->store->deleteAllExcept($this->pictures->referencedFiles());

        $io->text(sprintf('Queued %d re-checks, forgot %d senders, deleted %d unused files.', $queued, $pruned, $deleted));

        return Command::SUCCESS;
    }
}
