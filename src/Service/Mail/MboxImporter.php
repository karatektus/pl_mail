<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\RawMessageStorage;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MessageSyncer;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Existing mail brought in from a local archive rather than fetched from a
 * server.
 *
 * The source is Thunderbird's mailbox files — mbox, "From " separated, one
 * file per folder — read straight off disk. Nothing connects anywhere: the
 * bytes were already on this machine and the point of the exercise is that
 * they do not have to be fetched again from a POP3 server that would rather
 * not hand five years of history over twice.
 *
 * ## The shape
 *
 * One file is one folder, and a folder maps to an account plus the label its
 * contents should carry: Inbox/Sent/Drafts by name. Trash is refused by
 * default (there is an opt-in flag on the command, and nothing here turns it
 * on), because importing a bin imports everything somebody threw away.
 *
 * Each message goes through the SAME parse-and-store path a live POP3 sync
 * uses — webklex parses, MessageSyncer builds the row, PostIngestPipeline
 * threads it, harvests contacts and stores the raw bytes. That is deliberate
 * rather than convenient: a mirrored message must be indistinguishable from a
 * fetched one, because everything downstream (search, the body renderer, the
 * thread views, attachment thumbnails) is written against one shape and there
 * is no second shape to fall back on.
 *
 * ## Two decisions this class owns rather than inherits
 *
 * **Deduplication is by Message-ID per account, checked BEFORE parsing.** The
 * alternative — build every row and let a unique index say no — would still
 * be correct and several times slower on an archive like this one, where a
 * third of the messages are copies POP3 made of mail it had already fetched.
 * A message with no Message-ID at all is identified by the SHA-256 of its raw
 * bytes, keyed the same way; six of those exist in the archive this was
 * written against, and skipping by presence-in-file alone would import them
 * twice on a second run.
 *
 * **imapUid stays null, always.** The unique index on (mailbox_id, imap_uid)
 * permits any number of NULLs — that is what a POP3 message already relies on
 * — and inventing a number here would point the row at a UID the server
 * reassigns. A later live sync of the same mailbox simply never sees these
 * rows: they have no UID to collide with, and the archive is not the server.
 */
final readonly class MboxImporter
{
    /** Batch size for the post-ingest pipeline. Same scale MailImporter uses. */
    private const int BATCH = 50;

    public function __construct(
        private MessageSyncer          $messageSyncer,
        private LabelResolver          $labelResolver,
        private ThreadLabelSynchronizer $threadLabels,
        private PostIngestPipeline     $postIngest,
        private MessageRepository      $messages,
        private RawMessageStorage      $rawStorage,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * Import one mbox file.
     *
     * @param string   $path      absolute path to the mbox file
     * @param Account  $account   the account the mail belongs to
     * @param LabelRole $labelRole the role the folder's contents carry (Inbox/Sent/Drafts)
     * @param bool     $dryRun    count what WOULD happen and touch nothing
     *
     * @return MboxImportResult counts and the first few skips, for the report
     */
    public function importFile(string $path, Account $account, LabelRole $labelRole, bool $dryRun = false): MboxImportResult
    {
        $raw = @file_get_contents($path);

        if (false === $raw || '' === $raw) {
            throw new \RuntimeException(sprintf('Cannot read %s', $path));
        }

        $mailbox  = $this->mailboxFor($account, $labelRole, $dryRun);
        $label    = $dryRun ? null : $this->labelResolver->systemLabel($labelRole, $account);
        $existing = $this->knownMessageIds($account);

        $result = new MboxImportResult($path, $account->email ?? '', $labelRole->value);

        foreach ($this->split($raw) as $rawMessage) {
            $result->total++;

            $identity = $this->identityOf($rawMessage);

            if (null !== $identity && true === isset($existing[$identity])) {
                $result->skippedDuplicate++;
                continue;
            }

            if (true === $dryRun) {
                $result->wouldImport++;
                continue;
            }

            try {
                $message = $this->messageSyncer->buildMessageFromParsed(
                    ImapMessage::fromString($rawMessage, $this->imapConfig()),
                    $mailbox,
                    (int) $account->id,
                );
            } catch (\Throwable $failure) {
                $result->recordFailure($failure->getMessage());
                $this->logger->warning('Mbox import: a message could not be stored', [
                    'file'  => $path,
                    'error' => $failure->getMessage(),
                ]);
                continue;
            }

            // No UID, and none invented — see the class docblock.
            $message->imapUid = null;

            // A message with no Message-ID was identified by the hash of its
            // bytes, and that identity has to outlive this run or the second
            // run imports it again. The Message-ID column is the only durable
            // place an identity lives; writing the synthetic one there keeps
            // knownMessageIds() the single source of "have I seen this" and
            // costs nothing for messages that have a real ID, whose identity
            // is already the column's content. Marked so nothing downstream
            // mistakes a hash for an address the sender minted.
            if (null !== $identity && str_starts_with($identity, 'sha256:') && '' === $message->messageId) {
                $message->messageId = $identity;
            }

            if (null !== $label) {
                $message->addLabel($label);
            }

            $this->em->persist($message);

            if (null !== $identity) {
                $existing[$identity] = true;
            }

            $result->imported++;
            $result->pending[] = new IngestedMessage($message, $account, $rawMessage);

            if (self::BATCH === count($result->pending)) {
                $this->flushBatch($result);
            }
        }

        if (false === $dryRun && [] !== $result->pending) {
            $this->flushBatch($result);
        }

        return $result;
    }

    /**
     * Split an mbox into raw RFC822 messages.
     *
     * The separator is a line beginning "From " — the classic mbox convention
     * Thunderbird writes. A message BODY can contain such a line (the reason
     * mboxrd exists), and Thunderbird does not escape it, so this is a
     * heuristic and not a parser. It errs towards over-splitting, which loses
     * a fragment of one message rather than fusing two into an unparseable
     * blob; webklex reads the headers off the first part and the rest becomes
     * body, which is the survivable direction.
     *
     * WHY NO BLANK-LINE GUARD. Requiring a separator to follow a blank line
     * reads stricter and is strictly wrong for this data: Thunderbird does not
     * guarantee one, and the test fixture — a one-line body — exposed the
     * rule swallowing every message after the first. Over-splitting on a body
     * sentence beginning "From the desk of…" costs a fragment of one message;
     * under-splitting fuses two into one unparseable blob, and the second
     * message vanishes from the import entirely.
     *
     * @return \Generator<string>
     */
    private function split(string $raw): \Generator
    {
        $lines  = explode("\n", $raw);
        $buffer = [];

        foreach ($lines as $line) {
            $isSeparator = 0 === strncmp($line, 'From ', 5);

            if ($isSeparator) {
                if ([] !== $buffer) {
                    yield implode("\n", $buffer);
                }
                $buffer = [];
                $inside = true;
                continue;
            }

            if (false === $inside && [] === $buffer) {
                // Junk before the first separator (a mailbox header block) is
                // skipped rather than stored as a message with no headers.
                if ('' === trim($line)) {
                    continue;
                }
            }

            $buffer[] = $line;
        }

        if ([] !== $buffer) {
            yield implode("\n", $buffer);
        }
    }

    /**
     * The identity a message is deduplicated on: its Message-ID when it has
     * one, else the hash of its bytes.
     *
     * Read off the raw text rather than asking webklex, because the check runs
     * BEFORE parsing — that is what makes a re-run of a big archive cheap —
     * and a header grab of the first 64 KB is enough for a header that sits
     * first. A message whose Message-ID is further down than that has larger
     * problems than deduplication.
     */
    private function identityOf(string $rawMessage): ?string
    {
        $head = substr($rawMessage, 0, 65536);

        if (1 === preg_match('/^Message-I[dD]:\s*<([^>]+)>/mi', $head, $m)) {
            return MessageIdHelper::normalise($m[1]);
        }

        if (1 === preg_match('/^Message-I[dD]:\s*(\S+)/mi', $head, $m)) {
            return MessageIdHelper::normalise($m[1]);
        }

        return 'sha256:' . hash('sha256', $rawMessage);
    }

    /**
     * Message-IDs this account already holds, plus what a previous run of
     * THIS importer stored (raw paths are content-hashed and do not carry a
     * Message-ID).
     */
    private function knownMessageIds(Account $account): array
    {
        $ids = [];

        foreach ($this->messages->messageIdsForAccount($account) as $id) {
            if (null !== $id && '' !== $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * The mailbox a folder's contents are filed into, creating it if missing.
     *
     * One mailbox per special use, the way POP3 has one Inbox: an archive's
     * "Sent" maps to the account's Sent mailbox, not to a folder tree that
     * does not exist here.
     */
    private function mailboxFor(Account $account, LabelRole $labelRole, bool $dryRun): Mailbox
    {
        $use = $this->specialUseOf($labelRole);

        $existing = $this->em->getRepository(Mailbox::class)->findOneBy([
            'account'    => $account,
            'specialUse' => $use,
        ]);

        if (null !== $existing) {
            return $existing;
        }

        if (true === $dryRun) {
            // Real enough for counting: no row, no label binding, no writes.
            return new Mailbox();
        }

        $mailbox = new Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = $labelRole->value;
        $mailbox->fullPath      = $labelRole->value;
        $mailbox->specialUse    = $use;
        $mailbox->isSelectable  = true;
        $mailbox->isSyncEnabled = false;

        $this->em->persist($mailbox);

        $this->labelResolver->bindMailbox(
            $this->labelResolver->systemLabel($labelRole, $account),
            $mailbox,
        );

        $this->em->flush();

        return $mailbox;
    }

    private function specialUseOf(LabelRole $role): MailboxSpecialUse
    {
        return match ($role) {
            LabelRole::Inbox  => MailboxSpecialUse::INBOX,
            LabelRole::Sent   => MailboxSpecialUse::SENT,
            LabelRole::Drafts => MailboxSpecialUse::DRAFTS,
            // The command gates Trash behind an explicit flag, and the service
            // refuses it regardless: a library caller gets the same protection
            // the CLI user does, because the failure mode — silently importing
            // a bin — is one nobody wants from either door.
            LabelRole::Trash  => throw new \InvalidArgumentException(
                'Trash is not imported by design; an archive bin is everything somebody threw away.',
            ),
            default           => throw new \InvalidArgumentException(
                sprintf('Mbox import does not carry the %s role.', $role->value),
            ),
        };
    }

    private function imapConfig(): Config
    {
        // The same default configuration a live IMAP connection builds; fromString()
        // only reads the mask and charset settings off it.
        return Config::make();
    }

    /**
     * One pipeline run per batch: it threads, harvests contacts and stores raw
     * bytes, and its per-batch queries (correspondents, rules) should not run
     * once per message on a 772-message folder.
     */
    private function flushBatch(MboxImportResult $result): void
    {
        $this->em->flush();

        $this->postIngest->run($this->accountOf($result), $result->pending);

        foreach ($result->pending as $item) {
            if (null !== $item->message->thread) {
                $this->threadLabels->sync($item->message->thread);
            }
        }

        $this->em->flush();

        $result->pending = [];
    }

    /**
     * The account the pending batch belongs to. Every message in one import
     * call shares one, so the first is the answer; typed as a lookup rather
     * than a field so the result object stays serialisable for the report.
     */
    private function accountOf(MboxImportResult $result): Account
    {
        $first = $result->pending[0]->message->account
            ?? throw new \LogicException('flushBatch() with nothing pending');

        return $first;
    }
}
