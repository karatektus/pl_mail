<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Mail\MailBodySanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Populates Message::bodyHtmlSafe for messages that have an HTML body but no
 * sanitized copy yet — the render path reads bodyHtmlSafe exclusively.
 *
 * Keyset pagination by id (not offset, not a shrinking IS NULL cursor) so the
 * scan can flush + clear each batch without a server-side cursor, and can't
 * loop on rows the sanitizer legitimately leaves null (whitespace-only bodies).
 */
final readonly class SafeHtmlBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private MessageRepository      $messageRepository,
        private MailBodySanitizer      $bodySanitizer,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'safe-html';
    }

    public function getDescription(): string
    {
        return 'Regenerate Message.bodyHtmlSafe for messages with an HTML body but no sanitized copy.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->countPending();

        if (0 === $total) {
            $io->success('Nothing to backfill — every HTML body already has a sanitized copy.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId    = 0;
        $processed = 0;

        while (true) {
            $messages = $this->pendingBatch($lastId);

            if (count($messages) === 0) {
                break;
            }

            foreach ($messages as $message) {
                $lastId = (int) $message->getId();

                $this->bodySanitizer->sanitize($message);

                ++$processed;
                $io->progressAdvance();
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->progressFinish();
        $io->success(sprintf('Backfilled %d message(s).', $processed));

        return Command::SUCCESS;
    }

    private function countPending(): int
    {
        return (int) $this->pendingQueryBuilder(0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Message>
     */
    private function pendingBatch(int $afterId): array
    {
        return $this->pendingQueryBuilder($afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(self::BATCH_SIZE)
            ->getQuery()
            ->getResult();
    }

    private function pendingQueryBuilder(int $afterId): \Doctrine\ORM\QueryBuilder
    {
        return $this->messageRepository->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere('m.bodyHtml IS NOT NULL')
            ->andWhere("m.bodyHtml <> ''")
            ->andWhere('m.bodyHtmlSafe IS NULL')
            ->setParameter('afterId', $afterId);
    }
}
