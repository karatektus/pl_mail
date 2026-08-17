<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Entity\Mail\Message;
use App\Repository\Mail\MessageRepository;
use App\Service\Insight\InsightHarvester;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-read stored mail through the insight extractors — how existing mailboxes
 * get their parcels, flights and pull requests without a resync, and how a
 * better extractor next release upgrades everyone's cards.
 *
 * Idempotent by the harvester's upsert: the same mail lands on the same
 * dedupe key and refreshes the same row, and a dismissed insight stays
 * dismissed (see InsightHarvester). Extractors read persisted data only, so
 * no provider is asked anything.
 *
 * Every category is walked, unlike the proposal task's Primary-only rule,
 * because the interesting senders live exactly where proposals do not look:
 * carriers file under Updates, GitHub under Forums or Updates, ticket shops
 * under Promotions. supports() is the cheap gate that keeps the walk from
 * parsing every newsletter body.
 *
 * Offset paging with the same licence EventProposalBackfillTask claims: this
 * writes into mail_insight and never touches `message`, so the set it pages
 * over cannot shift under it.
 */
final readonly class InsightsBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private MessageRepository $messages,
        private InsightHarvester $harvester,
        private EntityManagerInterface $em,
    ) {
    }

    public function getName(): string
    {
        return 'insights';
    }

    public function getDescription(): string
    {
        return 'Re-read stored mail for parcels, flights, tickets and code review — the cards on the radar.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messages->count([]);

        if (0 === $total) {
            $io->success('No mail to read.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $offset = 0;
        $written = 0;

        while (true) {
            /** @var list<Message> $batch */
            $batch = $this->messages->findBy([], ['id' => 'ASC'], self::BATCH_SIZE, $offset);

            if (0 === count($batch)) {
                break;
            }

            foreach ($batch as $message) {
                $offset++;

                // The harvester shields per extractor; a failed flush is the
                // one fault left, and the manager-closed guard below is what
                // answers it.
                $count = $this->harvester->harvest($message);

                if ($count > 0) {
                    $written += $count;

                    $this->em->flush();
                }

                if (false === $this->em->isOpen()) {
                    $io->progressFinish();
                    $io->error(sprintf(
                        'Stopped at message %d: the entity manager closed, so nothing further could be saved.',
                        (int) $message->id,
                    ));

                    return Command::FAILURE;
                }

                $io->progressAdvance();
            }

            $this->em->clear();
        }

        $io->progressFinish();
        $io->success(sprintf('%d insight(s) written or refreshed.', $written));

        return Command::SUCCESS;
    }
}
