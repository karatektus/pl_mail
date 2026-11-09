<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Repository\Mail\Message\MessageRepository;
use App\Service\Label\LabelResolver;
use App\Service\Mail\ThreadStatusUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves the spam a filter archived into Spam, where the filter meant it.
 *
 * Until the fix in RuleActionExecutor::markSpam(), a "Mark as spam" filter
 * labelled the mail Spam here and archived it on the server: into the IMAP
 * Archive folder, the JMAP archive role, the Graph Archive folder. The next
 * sync found it there, so every message such a filter caught sat in the
 * archive, unread. The filter does it right now; the mail it already did it to
 * stays where it is until this runs.
 *
 * ── Which rows ───────────────────────────────────────────────────────────────
 * Mail wearing Spam that also wears Archive or has a copy in the server's
 * Archive folder (MessageRepository::findSpamInArchive()). Nothing else
 * removes Spam from a message, so the filter's label is still there to find
 * them by, and a message somebody filed as spam is spam wherever its copy is.
 *
 * ── How ──────────────────────────────────────────────────────────────────────
 * ThreadStatusUpdater::move() into Spam, the way a person moves it from the
 * Archive folder's list: the copy in Archive goes to Junk on the server, and
 * Archive comes off. An IMAP message on an account without a Junk folder is
 * skipped and counted — the move would land it in the archive again.
 *
 * Idempotent: a moved message no longer matches.
 */
final readonly class SpamInArchiveBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private MessageRepository      $messages,
        private LabelResolver          $labels,
        private ThreadStatusUpdater    $status,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'spam-in-archive';
    }

    public function getDescription(): string
    {
        return 'Move mail a "Mark as spam" filter archived on the server into Spam, and Junk on the server.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messages->countSpamInArchive();

        if (0 === $total) {
            $io->success('Nothing to repair — no spam is in the archive.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId  = 0;
        $moved   = 0;
        $skipped = 0;

        while (true) {
            $rows = $this->messages->findSpamInArchive($lastId, self::BATCH_SIZE);

            if ([] === $rows) {
                break;
            }

            foreach ($rows as $message) {
                $lastId = (int) $message->id;

                if (true === $this->repair($message)) {
                    ++$moved;
                } else {
                    ++$skipped;
                }

                $io->progressAdvance();
            }

            $this->em->clear();
        }

        $io->progressFinish();

        if (0 < $skipped) {
            $io->warning(sprintf('%d message(s) left in the archive: their IMAP account has no Junk folder to move them to.', $skipped));
        }

        $io->success(sprintf('%d message(s) moved to Spam.', $moved));

        return Command::SUCCESS;
    }

    private function repair(Message $message): bool
    {
        $account = $this->status->accountOf($message);
        $spam    = $this->labels->systemLabel(LabelRole::Spam, $account);
        $archive = self::archiveCopyOf($message);

        if (true === self::hasImapCopy($message) && null === $spam->bindingFor($account)?->mailbox) {
            return false;
        }

        // From the Archive folder when it is one plMail knows as Archive: then
        // that copy is the one that moves and Archive is the label that comes
        // off. Otherwise every place it is listed comes off, Archive with it.
        $from = null !== $archive?->label ? $archive : null;

        $this->status->move([$message], $spam, $from);

        return true;
    }

    private static function archiveCopyOf(Message $message): ?Mailbox
    {
        foreach ($message->locations as $location) {
            if (MailboxSpecialUse::ARCHIVE === $location->mailbox->specialUse) {
                return $location->mailbox;
            }
        }

        return null;
    }

    private static function hasImapCopy(Message $message): bool
    {
        foreach ($message->locations as $location) {
            if (null !== $location->imapUid) {
                return true;
            }
        }

        return false;
    }
}
