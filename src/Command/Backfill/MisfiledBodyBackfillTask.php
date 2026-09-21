<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Helper\CharsetHelper;
use App\Domain\Interface\UpgradeTaskInterface;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Mail\MisfiledBodyDetector;
use App\Service\Mail\MisfiledBodyUnpacker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Puts back the bodies that were stored as attachments before
 * MisfiledBodyDetector existed.
 *
 * Those messages render blank with one chip named something like `09704cea`,
 * and the mail the chip contains is the mail the message is supposed to show.
 * MessageSyncer stops new ones arriving; this is for the ones already here.
 *
 * ── Repairable without a mail server, which is the whole reason it exists ────
 * The bytes were saved. persistAttachment() wrote the part's content to
 * attachment storage on the way past, so the body has been on disk the entire
 * time it was missing from the screen — reading it back is the repair. That is
 * worth saying because the obvious alternative does not work: IMAP skips UIDs
 * it already has, so a re-sync would not re-fetch these, and deleting the
 * account to force one would throw away everything else with them.
 *
 * ── The one thing that is worse than at ingest ───────────────────────────────
 * MessagePart keeps `content_type` without the parameters, so the part's
 * declared charset is not stored and cannot be recovered here. CharsetHelper is
 * given null and works it out from the bytes, which is exact for UTF-8 — most
 * of this mail — and a well-reasoned guess otherwise. Re-fetching by UID would
 * know for certain, at the cost of a round trip per message to a server that
 * has nothing else to tell us. The guess is the better trade, and this comment
 * is here so the next person does not have to re-derive that it was one.
 *
 * Idempotent: a repaired message no longer matches the predicate that selects
 * it, because the part is gone and the body is not empty any more. That is
 * what lets it be BOTH interfaces below — an upgrade task must survive being
 * interrupted and re-run, and a backfill an administrator types twice must do
 * nothing the second time.
 */
final readonly class MisfiledBodyBackfillTask implements BackfillTaskInterface, UpgradeTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private MessageRepository       $messages,
        private MessageThreadRepository $threads,
        private AttachmentResolver      $attachments,
        private MisfiledBodyDetector    $detector,
        private MisfiledBodyUnpacker    $unpacker,
        private MailBodySanitizer       $sanitizer,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {}

    /**
     * RENAMED, and the rename is the point.
     *
     * The ledger keys on this string, so an installation that has already run
     * `misfiled-bodies` has a row saying so and would never run it again. That
     * first pass looked only for text/plain and text/html parts — it completed,
     * reported success, and repaired nothing on the installs whose bodies were
     * wrapped in a container, which is the very shape this now handles. A new
     * name is how the mechanism was designed to say "that was not the same
     * job"; see UpgradeTaskInterface::getName().
     */
    public function getName(): string
    {
        return 'misfiled-bodies-v2';
    }

    public function getDescription(): string
    {
        return 'Restore message bodies that were stored as nameless attachments, leaving the message blank.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messages->countWithMisfiledBodyPart();

        if (0 === $total) {
            $io->success('Nothing to repair — no message is missing a body this way.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId   = 0;
        $repaired = 0;
        $skipped  = 0;

        while (true) {
            $messages = $this->messages->findWithMisfiledBodyPart($lastId, self::BATCH_SIZE);

            if (count($messages) === 0) {
                break;
            }

            foreach ($messages as $message) {
                $lastId = (int) $message->id;

                $outcome = $this->repair($message);

                if (true === $outcome) {
                    ++$repaired;
                } else {
                    ++$skipped;
                }

                $io->progressAdvance();
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->progressFinish();

        // Derived counters, rebuilt rather than patched by deltas — the same
        // reasoning ReclassifyAttachmentsCommand gives.
        $this->threads->recomputeAttachmentCounts();

        $io->success(sprintf('%d message(s) repaired, %d left alone.', $repaired, $skipped));

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return bool true when at least one body was put back
     */
    private function repair(Message $message): bool
    {
        $reclaimed = false;

        foreach ($message->messageParts->toArray() as $part) {
            if (false === $this->isMisfiledBody($message, $part)) {
                continue;
            }

            $content = $this->contentOf($part);

            if (null === $content) {
                continue;
            }

            if (true === $this->detector->isContainer($part->contentType)) {
                // A MIME block, not text. Opening it also settles the charset,
                // which is why this path does not go near CharsetHelper: inside
                // a message of its own the HTML is a body part again, and
                // webklex converts those.
                $opened = $this->unpacker->unpack((string) $part->contentType, $content);

                if (null === $opened) {
                    continue;
                }

                $message->bodyHtml = $opened['html'];
                $message->bodyText = $opened['text'];
            } else {
                // No charset to pass: see the class docblock. CharsetHelper
                // reads the bytes when it is told nothing, which is the right
                // behaviour for the common case and never worse than storing
                // them raw.
                $content = CharsetHelper::toUtf8($content, null);

                if ('html' === $this->detector->slotFor($part->contentType)) {
                    $message->bodyHtml = $content;
                } else {
                    $message->bodyText = $content;
                }
            }

            $message->messageParts->removeElement($part);
            $this->em->remove($part);

            $reclaimed = true;
        }

        if (false === $reclaimed) {
            return false;
        }

        // The chip has to stop being counted as well as stop existing.
        $message->hasAttachments = $this->hasRealAttachment($message);

        // bodyHtmlSafe is what the reading pane renders; bodyHtml alone would
        // repair the database and leave the screen exactly as blank as before.
        // On ingest PostIngestPipeline does this — here nothing else will.
        $this->sanitizer->sanitize($message);

        return true;
    }

    private function isMisfiledBody(Message $message, MessagePart $part): bool
    {
        if (false === $this->detector->looksLikeStoredHash($part->filename)) {
            return false;
        }

        if (false === $this->detector->isBody($part->contentType, null, null)) {
            return false;
        }

        // A container can fill both slots, so it only qualifies when both are
        // empty — the same rule MessageSyncer applies at ingest.
        if (true === $this->detector->isContainer($part->contentType)) {
            return '' === (string) $message->bodyHtml && '' === (string) $message->bodyText;
        }

        $isHtml = 'html' === $this->detector->slotFor($part->contentType);

        return '' === ($isHtml ? (string) $message->bodyHtml : (string) $message->bodyText);
    }

    /**
     * The part's stored bytes, or null when they cannot be read.
     *
     * Skipped rather than fatal: a blob lost to a half-restored backup is one
     * message that stays blank, and stopping the walk there would leave every
     * later message blank too.
     */
    private function contentOf(MessagePart $part): ?string
    {
        try {
            $path    = $this->attachments->absolutePathFor($part);
            $content = @file_get_contents($path);
        } catch (Throwable $e) {
            $this->logger->warning('Could not read a misfiled body part', [
                'partId' => $part->id,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }

        return false !== $content ? $content : null;
    }

    private function hasRealAttachment(Message $message): bool
    {
        foreach ($message->messageParts as $part) {
            if (true !== $part->isInline) {
                return true;
            }
        }

        return false;
    }
}
