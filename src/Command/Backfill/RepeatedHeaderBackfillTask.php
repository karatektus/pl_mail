<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Helper\CharsetHelper;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Helper\RawMessageStorage;
use App\Domain\Helper\RepeatedHeaderHelper;
use App\Entity\Mail\Message;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\MailBodySanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Takes the repeated header block out of IMAP mail stored before
 * MessageSyncer learned to drop it (#173).
 *
 * Those messages open with their own `Delivered-To: …` block above the
 * content, and their .eml holds the headers twice. MessageSyncer stops new
 * ones arriving; this is for the ones already here.
 *
 * ── Repairable from the .eml, which is why it exists ─────────────────────────
 * The sync wrote "header, blank line, what the server sent as the body", so
 * the file is the header literal followed by the doubled body, and splitting
 * it at the first blank line gives back exactly the pair the sync had.
 * RepeatedHeaderHelper decides the same way it does at ingest. A re-sync would
 * not help: IMAP skips UIDs it already has.
 *
 * ── What is rewritten, and what is left alone ────────────────────────────────
 * The .eml, always: it is meant to be the message, once.
 *
 * The bodies, ONLY for a single-part message. There the whole doubled text was
 * the one part, so body_html or body_text is the header block plus the body,
 * and a fresh parse of the repaired file is the right answer. A multipart
 * message is different: its parts start after the boundary, so the bodies
 * were parsed correctly, and they may hold a repair the sync made
 * (MisfiledBodyDetector) that a plain re-parse would not repeat. Overwriting
 * them would trade a correct body for a possibly worse one.
 *
 * Attachments are not touched. A single-part message has none, and a
 * multipart one's were parsed from the parts, like its bodies.
 *
 * ── Every IMAP message with an .eml is read ──────────────────────────────────
 * The doubled block is invisible in the database for a multipart message, so
 * there is no column to select the affected ones by. One file read per
 * message, once, is the price; a backfill rather than an upgrade task so an
 * administrator chooses when to pay it.
 *
 * Idempotent: a repaired file no longer starts its body with its header
 * block, so the second run changes nothing.
 */
final readonly class RepeatedHeaderBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private MessageRepository      $messages,
        private RawMessageStorage      $rawStorage,
        private MailBodySanitizer      $sanitizer,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    public function getName(): string
    {
        return 'repeated-headers';
    }

    public function getDescription(): string
    {
        return 'Remove the header block some IMAP servers repeated at the top of a message body, from stored mail and its original.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messages->countImapWithStoredRaw();

        if (0 === $total) {
            $io->success('Nothing to read — no IMAP message has its original stored.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId   = 0;
        $repaired = 0;

        while (true) {
            $messages = $this->messages->findImapWithStoredRaw($lastId, self::BATCH_SIZE);

            if (count($messages) === 0) {
                break;
            }

            foreach ($messages as $message) {
                $lastId = (int) $message->id;

                if (true === $this->repair($message)) {
                    ++$repaired;
                }

                $io->progressAdvance();
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->progressFinish();
        $io->success(sprintf('%d message(s) repaired, %d read.', $repaired, $total));

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return bool true when the message carried a repeated header block
     */
    private function repair(Message $message): bool
    {
        $path = (string) $message->rawPath;

        try {
            if (false === $this->rawStorage->exists($path)) {
                return false;
            }

            $raw = (string) file_get_contents($this->rawStorage->getAbsolutePath($path));

            // MessageSyncer::rawOf() joins the halves with exactly this.
            $split = strpos($raw, "\r\n\r\n");

            if (false === $split) {
                return false;
            }

            $header = substr($raw, 0, $split);
            $body   = RepeatedHeaderHelper::strip($header, substr($raw, $split + 4));

            if (null === $body) {
                return false;
            }

            $repaired = $header . "\r\n\r\n" . $body;

            // Parsed before anything is written, so a message the library
            // cannot read is left exactly as it was rather than half repaired.
            $parsed = ImapMessage::fromString($repaired, ImapConnectionFactory::config());
        } catch (Throwable $e) {
            // Skipped rather than fatal: one unreadable file is one message
            // that stays as it was, and stopping here would leave every later
            // one unrepaired too.
            $this->logger->warning('Could not read a stored message for the repeated-header repair', [
                'messageId' => $message->id,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }

        $message->rawPath = $this->rawStorage->store((int) $message->account->id, (int) $message->id, $repaired);

        if (IMAP::MESSAGE_TYPE_MULTIPART !== $parsed->getStructure()?->type) {
            $message->bodyText = CharsetHelper::ensureUtf8($parsed->getTextBody());
            $message->bodyHtml = CharsetHelper::ensureUtf8($parsed->getHTMLBody());

            // bodyHtmlSafe is what the reading pane renders; bodyHtml alone
            // would repair the database and leave the screen as it was.
            $this->sanitizer->sanitize($message);
        }

        return true;
    }
}
