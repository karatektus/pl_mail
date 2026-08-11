<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Helper\RecipientHeaderHelper;
use App\Entity\Mail\Message;
use App\Repository\Mail\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-derives to/cc from the headers the row already stores.
 *
 * Every message synced over IMAP before MessageSyncer::addressesOf() was
 * fixed has empty recipient columns: webklex's Attribute is not Traversable, so
 * the foreach that read it iterated nothing and stored nothing. The headers
 * themselves were never lost — they go into the `headers` jsonb bag by a
 * different code path — so the columns are re-derivable from the row itself,
 * with no mail server, no raw MIME and no resync involved.
 *
 * Only fills what is empty. A row whose recipients were captured correctly
 * (Gmail, Graph, anything synced after the fix) is left exactly as it is, so
 * running this cannot undo a good capture with a worse re-parse.
 *
 * Idempotent, and cheap to re-run: a row with no To: header in its bag stays
 * empty and is simply counted as such — undisclosed-recipients deliveries and
 * mailing lists genuinely have nobody to name, and the message header says so
 * out loud rather than omitting the line.
 */
final readonly class RecipientsBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 500;

    /** What fill() did to one row. */
    private const int ALREADY_HAD_THEM = 0;
    private const int FILLED           = 1;
    private const int NOTHING_TO_FILL  = 2;

    public function __construct(
        private MessageRepository      $messageRepository,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'recipients';
    }

    public function getDescription(): string
    {
        return 'Re-derive empty To/Cc recipient lists from each message\'s stored headers';
    }

    public function run(SymfonyStyle $io): int
    {
        $afterId  = 0;
        $seen     = 0;
        $filled   = 0;
        $noHeader = 0;

        while (true) {
            $ids = $this->messageRepository->findIdsAfter($afterId, self::BATCH_SIZE);

            if (0 === count($ids)) {
                break;
            }

            foreach ($this->messageRepository->findByIds($ids) as $message) {
                match ($this->fill($message)) {
                    self::FILLED          => $filled++,
                    self::NOTHING_TO_FILL => $noHeader++,
                    default               => null,
                };
            }

            $seen   += count($ids);
            $afterId = $ids[count($ids) - 1];

            $this->em->flush();
            $this->em->clear();

            $io->writeln(sprintf('  … %d messages scanned, %d filled', $seen, $filled));
        }

        $io->success(sprintf(
            '%d messages scanned, %d given recipients, %d still empty (no To:/Cc: header to read).',
            $seen,
            $filled,
            $noHeader,
        ));

        return 0;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function fill(Message $message): int
    {
        $needsTo = null === $message->toAddresses || 0 === count($message->toAddresses);
        $needsCc = null === $message->ccAddresses || 0 === count($message->ccAddresses);

        if (false === $needsTo && false === $needsCc) {
            return self::ALREADY_HAD_THEM;
        }

        $headers = $message->headers ?? [];
        $changed = false;

        if (true === $needsTo) {
            $to = RecipientHeaderHelper::addresses($headers, 'to');

            if ([] !== $to) {
                $message->toAddresses = $to;
                $changed = true;
            }
        }

        if (true === $needsCc) {
            $cc = RecipientHeaderHelper::addresses($headers, 'cc');

            if ([] !== $cc) {
                $message->ccAddresses = $cc;
                $changed = true;
            }
        }

        return true === $changed ? self::FILLED : self::NOTHING_TO_FILL;
    }
}
