<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Repository\Mail\MessageRepository;
use App\Service\Mail\HeaderNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rewrites stored header bags into the canonical key form.
 *
 * Messages synced before HeaderNormalizer existed carry provider-shaped keys:
 * "List-Id" from Gmail and Graph, "list_id" from php-imap. Header conditions in
 * mail rules look a key up directly, so those rows would never match until they
 * are folded to the same form as everything arriving now.
 *
 * Idempotent — normalising an already-normalised bag is the identity — so it is
 * safe to re-run, and safe to run while a sync is in progress.
 *
 * On losing information: folding key *case* loses nothing, since RFC 5322 field
 * names are case-insensitive. Undoing php-imap's underscore mangling is a
 * judgement call ("x_foo" was almost certainly "X-Foo", though "X_Foo" is legal)
 * — and it only applies to IMAP rows, which are the only ones with a stored raw
 * copy to fall back on. Values are never touched.
 */
final readonly class HeaderNormalizationBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private MessageRepository      $messageRepository,
        private HeaderNormalizer       $normalizer,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'headers';
    }

    public function getDescription(): string
    {
        return 'Fold stored header keys to the canonical lowercase, dash-separated form';
    }

    public function run(SymfonyStyle $io): int
    {
        $afterId = 0;
        $seen    = 0;
        $changed = 0;

        while (true) {
            $ids = $this->messageRepository->findIdsWithHeaders($afterId, self::BATCH_SIZE);

            if (0 === count($ids)) {
                break;
            }

            foreach ($this->messageRepository->findByIds($ids) as $message) {
                $headers = $message->headers;

                if (null === $headers || 0 === count($headers)) {
                    continue;
                }

                $normalized = $this->normalizer->normalize($headers);

                if ($normalized !== $headers) {
                    $message->headers = $normalized;
                    $changed++;
                }
            }

            $seen   += count($ids);
            $afterId = $ids[count($ids) - 1];

            $this->em->flush();
            $this->em->clear();

            $io->writeln(sprintf('  … %d scanned, %d rewritten', $seen, $changed));
        }

        $io->success(sprintf('%d messages scanned, %d header bags rewritten.', $seen, $changed));

        return 0;
    }
}
