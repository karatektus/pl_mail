<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MessageRepository;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\MessageEraser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fold the messages stored before #415 that are copies of one email into one
 * message with all their locations.
 *
 * Until #415 every server copy was a message of its own: Proton's All Mail
 * beside INBOX, a Gmail label folder beside the inbox, a copy a client made.
 * New copies are recognised now (ImapCopies), but the rows already stored
 * stay doubled until something folds them — and #414's conversation goes on
 * showing fourteen messages for seven.
 *
 * ── When ─────────────────────────────────────────────────────────────────────
 * From the sweep, right after it has given the folder's rows their size
 * (MessageRepository::backfillSizes()), which is the moment this folder's
 * duplicates become recognisable. A bounded number of emails per sweep, so a
 * large account is folded over a few polls rather than in one, and nothing
 * has to remember where it stopped: what is folded is no longer a duplicate.
 *
 * ── Which rows are one email ─────────────────────────────────────────────────
 * The rule new copies are recognised by (ImapCopies): one account, the same
 * Message-ID and the same size, and no two server ids that differ. Only rows
 * the server keeps somewhere — a row with no location is Gmail's, Graph's,
 * JMAP's or a draft's, and not this class's.
 *
 * ── Which row stays ──────────────────────────────────────────────────────────
 * The one carrying a Gmail identity, if any does (Gmailify linked it, and the
 * link should survive), then the oldest: the id JMAP clients have held
 * longest. It takes every other row's locations, labels and anything that
 * points at it — calendar links, proposals, insights, the POP3 ledger — and
 * is read if any was read, starred if any was starred. Its placement labels
 * are worked out again from the copies it now has (placeByCopies()). The others are erased
 * the way any message is (MessageEraser), so a JMAP client is told they are
 * gone.
 */
final readonly class ImapCopyMerger
{
    /** Emails folded per sweep: enough to finish an account in a day of polls. */
    public const int PER_SWEEP = 200;

    public function __construct(
        private MessageRepository       $messages,
        private MessageEraser           $eraser,
        private ImapCopies              $copies,
        private ThreadLabelSynchronizer $threadLabels,
        private ThreadMerger            $threadMerger,
        private StateManager            $stateManager,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {
    }

    /**
     * Fold the duplicates among the messages this folder keeps.
     *
     * @return int emails folded
     */
    public function foldKeptIn(Mailbox $mailbox, int $limit = self::PER_SWEEP): int
    {
        $groups = $this->messages->findDuplicateCopyGroups($mailbox, $limit);
        $folded = 0;

        foreach ($groups as $ids) {
            $rows = $this->messages->findBy(['id' => $ids]);

            usort($rows, static fn (Message $a, Message $b): int => array_search($a->id, $ids, true) <=> array_search($b->id, $ids, true));

            if (count($rows) < 2) {
                continue;
            }

            $this->em->wrapInTransaction(function () use ($rows): void {
                $this->fold(array_shift($rows), $rows);
            });

            ++$folded;
        }

        if ($folded > 0) {
            $this->logger->info('Folded copies of one email stored as separate messages', [
                'mailbox' => $mailbox->fullPath,
                'emails'  => $folded,
            ]);
        }

        return $folded;
    }

    /**
     * @param list<Message> $copies
     */
    private function fold(Message $survivor, array $copies): void
    {
        $connection = $this->em->getConnection();
        $addresses  = [];

        foreach ($copies as $copy) {
            foreach ($copy->locations as $location) {
                $addresses[] = [$location->mailbox, $location->imapUid, $location->serverFlags];
            }

            foreach ($copy->labels as $label) {
                $survivor->addLabel($label);
            }

            // Two threads that held one email are one conversation. Doubled
            // mail threaded unpredictably — a reply joined whichever copy of
            // its parent it met — so #414's conversation could be split across
            // two threads, each holding half of each copy.
            if (null !== $survivor->thread && null !== $copy->thread && $survivor->thread !== $copy->thread) {
                $this->threadMerger->merge($survivor->thread, $copy->thread);
            }

            $survivor->seenAt    = self::earlier($survivor->seenAt, $copy->seenAt);
            $survivor->starredAt = self::earlier($survivor->starredAt, $copy->starredAt);
            $survivor->gmailId     ??= $copy->gmailId;
            $survivor->jmapId      ??= $copy->jmapId;
            $survivor->serverEmailId ??= $copy->serverEmailId;

            // What points at the copy points at the email: moved across, where
            // the survivor has no row of its own for the same thing already.
            $params = ['survivor' => $survivor->id, 'copy' => $copy->id];

            $connection->executeStatement('UPDATE event_source_link l SET message_id = :survivor WHERE l.message_id = :copy AND NOT EXISTS (SELECT 1 FROM event_source_link s WHERE s.message_id = :survivor AND s.event_id = l.event_id AND s.extractor = l.extractor)', $params);
            $connection->executeStatement('UPDATE event_proposal p SET message_id = :survivor WHERE p.message_id = :copy AND NOT EXISTS (SELECT 1 FROM event_proposal s WHERE s.message_id = :survivor AND s.starts_at = p.starts_at)', $params);
            $connection->executeStatement('UPDATE mail_insight SET message_id = :survivor WHERE message_id = :copy', $params);
            $connection->executeStatement('UPDATE insight_report SET message_id = :survivor WHERE message_id = :copy', $params);
            $connection->executeStatement('UPDATE pop3_uidl SET message_id = :survivor WHERE message_id = :copy', $params);

            // Files are per message; a path the survivor names too is not the
            // copy's to delete.
            if (null !== $copy->rawPath && $copy->rawPath === $survivor->rawPath) {
                $copy->rawPath = null;
            }
        }

        $survivor->flags = self::flagsOf($survivor);

        // Erased first and flushed, so the addresses are free when the
        // survivor takes them: a UID is unique in its folder.
        $this->eraser->eraseAll($copies);
        $this->em->flush();

        foreach ($addresses as [$mailbox, $uid, $serverFlags]) {
            $survivor->addLocation($mailbox, $uid)->serverFlags = $serverFlags;
        }

        $this->placeByCopies($survivor);

        if (null !== $survivor->thread) {
            $this->threadLabels->sync($survivor->thread);
            $this->stateManager->recordThreadsTouched((int) $survivor->account->id, [(int) $survivor->thread->id]);
        }

        $this->stateManager->recordUpdated((int) $survivor->account->id, JmapObjectType::Email, (string) $survivor->id);

        $this->em->flush();
    }

    /**
     * The labels that say where the email is, worked out again from where it
     * now is.
     *
     * A union of the copies' labels would be wrong exactly where this matters:
     * the All Mail copy wore All Mail before #415 and Archive after, and the
     * email it is folded into is in the inbox. So the labels a folder gives —
     * any label bound to a folder of the account, and Archive — are taken off
     * and put back from the copies, by the rule every other path uses
     * (ImapCopies::labelFor()). Labels that are tags stay as they were.
     */
    private function placeByCopies(Message $email): void
    {
        $account = $email->account;

        foreach ($email->labels->toArray() as $label) {
            if (LabelRole::Archive === $label->role || null !== $label->bindingFor($account)?->mailbox) {
                $email->removeLabel($label);
            }
        }

        $onlyMirrored = true;

        foreach ($email->locations as $location) {
            if (false === $location->mailbox->mirrorsOtherFolders) {
                $onlyMirrored = false;
                $label        = $this->copies->labelFor($location->mailbox);

                if (null !== $label) {
                    $email->addLabel($label);
                }
            }
        }

        if (true === $onlyMirrored && 0 < count($email->locations)) {
            $archive = $this->copies->labelFor($email->locations->first()->mailbox);

            if (null !== $archive) {
                $email->addLabel($archive);
            }
        }
    }

    private static function earlier(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): ?\DateTimeImmutable
    {
        if (null === $a || null === $b) {
            return $a ?? $b;
        }

        return min($a, $b);
    }

    /**
     * The survivor's flags with \Seen and \Flagged as the fold left them.
     *
     * @return list<string>
     */
    private static function flagsOf(Message $message): array
    {
        $flags = array_values(array_filter(
            $message->flags,
            static fn (string $flag): bool => MessageFlag::SEEN->value !== $flag && MessageFlag::FLAGGED->value !== $flag,
        ));

        if (null !== $message->seenAt) {
            $flags[] = MessageFlag::SEEN->value;
        }

        if (null !== $message->starredAt) {
            $flags[] = MessageFlag::FLAGGED->value;
        }

        return MessageFlag::canonicalList($flags);
    }
}
