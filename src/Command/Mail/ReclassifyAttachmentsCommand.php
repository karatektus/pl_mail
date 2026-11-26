<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Entity\Mail\Message;
use App\Repository\Mail\MessagePart\MessagePartRepository;
use App\Repository\Mail\Message\MessageRepository;
use App\Repository\Mail\MessageThread\MessageThreadRebuildRepository;
use App\Service\Mail\InlineAttachmentReclassifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs message parts stored while "has a Content-ID" was treated as
 * "is inline". Those parts were saved as inline, so real attachments never
 * appeared under the message and message.has_attachments stayed false.
 *
 * Re-runs the detector's rule against the stored body HTML through
 * InlineAttachmentReclassifier, the same one the upgrade task
 * `attachment-unreferenced-inline` uses (#753), then recomputes
 * thread.attachment_count. Idempotent. Kept beside that task for its
 * --dry-run, which says how many parts would change before anything does.
 */
#[AsCommand(
    name: 'app:attachments:reclassify',
    description: 'Recompute inline/attachment classification for stored message parts',
)]
final class ReclassifyAttachmentsCommand extends Command
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface   $em,
        private readonly MessageRepository        $messages,
        private readonly MessagePartRepository    $messageParts,
        private readonly MessageThreadRebuildRepository $messageThreadRebuildRepository,
        private readonly InlineAttachmentReclassifier $reclassifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report changes without writing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        $messageIds = $this->messageParts->findDistinctMessageIds();

        $io->text(sprintf('%d messages with parts to inspect.', count($messageIds)));

        $changedParts    = 0;
        $changedMessages = 0;
        $processed       = 0;

        foreach (array_chunk($messageIds, self::BATCH_SIZE) as $chunk) {
            /** @var list<Message> $messages */
            $messages = $this->messages->findBy(['id' => $chunk]);

            foreach ($messages as $message) {
                $hadAttachments = $message->hasAttachments;
                $changed        = $this->reclassifier->reclassify($message);

                $changedParts += $changed;

                if (0 < $changed || $hadAttachments !== $message->hasAttachments) {
                    ++$changedMessages;
                }
            }

            $processed += count($messages);

            if (false === $dryRun) {
                $this->em->flush();
            }

            $this->em->clear();
            $io->text(sprintf('  … %d/%d', $processed, count($messageIds)));
        }

        if (false === $dryRun) {
            // Thread counters are derived, so they are rebuilt from the
            // repaired messages rather than patched by deltas.
            $this->messageThreadRebuildRepository->recomputeAttachmentCounts();
        }

        $io->success(sprintf(
            '%s%d parts reclassified across %d messages.',
            $dryRun ? '[dry-run] ' : '',
            $changedParts,
            $changedMessages,
        ));

        return Command::SUCCESS;
    }
}
