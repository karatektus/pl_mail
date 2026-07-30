<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Service\Mail\InlineAttachmentDetector;
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
 * Re-runs InlineAttachmentDetector against the stored body HTML and rewrites
 * is_inline / disposition, then recomputes message.has_attachments and
 * thread.attachment_count. Idempotent.
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
        private readonly InlineAttachmentDetector $inlineDetector,
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

        $messageIds = array_column(
            $this->em->createQuery(
                'SELECT DISTINCT IDENTITY(p.message) AS id FROM ' . MessagePart::class . ' p'
            )->getArrayResult(),
            'id',
        );

        $io->text(sprintf('%d messages with parts to inspect.', count($messageIds)));

        $changedParts    = 0;
        $changedMessages = 0;
        $processed       = 0;

        foreach (array_chunk($messageIds, self::BATCH_SIZE) as $chunk) {
            /** @var list<Message> $messages */
            $messages = $this->em->getRepository(Message::class)->findBy(['id' => $chunk]);

            foreach ($messages as $message) {
                $bodyHtml       = $message->getBodyHtml();
                $hasAttachments = false;
                $touched        = false;

                foreach ($message->getMessageParts() as $part) {
                    $isInline = $this->inlineDetector->isInline(
                        $part->getDisposition(),
                        $part->getContentId(),
                        $bodyHtml,
                    );

                    if ($isInline !== $part->isInline()) {
                        $part->setIsInline($isInline);
                        $part->setDisposition($isInline ? 'inline' : 'attachment');
                        ++$changedParts;
                        $touched = true;
                    }

                    if (false === $isInline) {
                        $hasAttachments = true;
                    }
                }

                if ($hasAttachments !== $message->hasAttachments()) {
                    $message->setHasAttachments($hasAttachments);
                    $touched = true;
                }

                if (true === $touched) {
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
            $this->recountThreads();
        }

        $io->success(sprintf(
            '%s%d parts reclassified across %d messages.',
            $dryRun ? '[dry-run] ' : '',
            $changedParts,
            $changedMessages,
        ));

        return Command::SUCCESS;
    }

    /**
     * Thread counters are derived, so rebuild them from the repaired messages
     * instead of trying to patch the deltas.
     */
    private function recountThreads(): void
    {
        $this->em->getConnection()->executeStatement(<<<'SQL'
            UPDATE message_thread t
            SET attachment_count = COALESCE((
                SELECT COUNT(*) FROM message m
                WHERE m.thread_id = t.id AND m.has_attachments = true
            ), 0)
        SQL);
    }
}
