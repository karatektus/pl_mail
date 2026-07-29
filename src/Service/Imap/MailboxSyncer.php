<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Account;
use App\Entity\Mailbox;
use App\Repository\MailboxRepository;
use App\Service\Label\LabelResolver;
use Doctrine\ORM\EntityManagerInterface;
use Webklex\PHPIMAP\Folder;

/**
 * Mirrors the IMAP folder tree into Mailbox rows (pure sync infrastructure)
 * and ensures every mailbox is linked to its Label:
 *   - special-use folders → system role labels
 *   - everything else → nested custom label chains from the folder path
 *
 * This is also the incoming half of best-effort label sync-back: a folder
 * created by another client shows up here and gets its label chain created.
 *
 * Gmail-API accounts are hard-excluded: their organization comes from
 * GmailLabelSyncer only. Running an IMAP folder listing against a Gmail
 * account would create "[Gmail]/…" mailbox rows and bogus label chains.
 */
readonly class MailboxSyncer
{
    public function __construct(
        private MailboxRepository      $mailboxRepository,
        private EntityManagerInterface $em,
        private ImapConnectionFactory  $imapConnectionFactory,
        private LabelResolver          $labelResolver,
    ) {}

    public function syncForAccount(Account $account): array
    {
        if (true === $account->isGmail() || true === $account->isMicrosoft()) {
            return ['created' => 0, 'updated' => 0, 'deleted' => 0];
        }

        $client = $this->imapConnectionFactory->connect($account);

        $serverFolders = $client->getFolders(false);

        $existing = $this->mailboxRepository->findIndexedByFullPath($account);

        $result = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
        ];
        $seen = [];

        foreach ($serverFolders as $folder) {
            $fullPath = $folder->path;
            $seen[]   = $fullPath;

            if (true === isset($existing[$fullPath])) {
                $this->update($existing[$fullPath], $folder, $account);
                $result['updated']++;
            } else {
                $this->create($account, $folder);
                $result['created']++;
            }
        }

        foreach ($existing as $fullPath => $mailbox) {
            if (false === in_array($fullPath, $seen, true)) {
                $this->em->remove($mailbox);
                $result['deleted']++;
            }
        }

        $this->em->flush();
        $client->disconnect();

        return $result;
    }

    private function create(Account $account, Folder $folder): void
    {
        $mailbox = new Mailbox();
        $mailbox->setAccount($account);
        $mailbox->setIsSyncEnabled(true);
        $mailbox->setIsIdleEnabled(in_array(
            $this->detectSpecialUse($folder)?->value,
            ['\\Inbox', '\\Junk'],
            true,
        ));
        // Persist before hydrate: hydrate() binds the folder to a label, and
        // LabelResolver flushes to mint the binding id — the mailbox has to be
        // managed by then or the binding's FK points at nothing. Every
        // non-nullable column is set inside hydrate() before that flush.
        $this->em->persist($mailbox);
        $this->hydrate($mailbox, $folder, $account);
    }

    private function update(Mailbox $mailbox, Folder $folder, Account $account): void
    {
        $this->hydrate($mailbox, $folder, $account);
    }

    private function hydrate(Mailbox $mailbox, Folder $folder, Account $account): void
    {
        $mailbox->setName($folder->name);
        $mailbox->setFullPath($folder->path);
        $mailbox->setDelimiter($folder->delimiter);
        $mailbox->setSpecialUse($this->detectSpecialUse($folder));

        $this->linkLabel($mailbox, $account);
    }

    private function linkLabel(Mailbox $mailbox, Account $account): void
    {
        $specialUse = $mailbox->getSpecialUse();

        if (null !== $specialUse) {
            $this->labelResolver->bindMailbox(
                $this->labelResolver->systemLabel(LabelRole::fromSpecialUse($specialUse), $account),
                $mailbox,
            );

            return;
        }

        $segments = $this->labelResolver->segmentsFromImapPath(
            (string) $mailbox->getFullPath(),
            $mailbox->getDelimiter(),
        );

        if (count($segments) === 0) {
            $segments = [(string) $mailbox->getName()];
        }

        $label = $this->labelResolver->customChain($segments, $account);

        if (null !== $label) {
            $this->labelResolver->bindMailbox($label, $mailbox);
        }
    }

    private function detectSpecialUse(Folder $folder): ?MailboxSpecialUse
    {
        $name = strtolower($folder->name);

        $nameMap = [
            'inbox'            => MailboxSpecialUse::INBOX,
            'sent'             => MailboxSpecialUse::SENT,
            'sent messages'    => MailboxSpecialUse::SENT,
            'drafts'           => MailboxSpecialUse::DRAFTS,
            'draft'            => MailboxSpecialUse::DRAFTS,
            'trash'            => MailboxSpecialUse::TRASH,
            'deleted'          => MailboxSpecialUse::TRASH,
            'deleted messages' => MailboxSpecialUse::TRASH,
            'junk'             => MailboxSpecialUse::JUNK,
            'spam'             => MailboxSpecialUse::JUNK,
            'spambucket'       => MailboxSpecialUse::JUNK,
            'archive'          => MailboxSpecialUse::ARCHIVE,
        ];

        if (true === array_key_exists($name, $nameMap)) {
            return $nameMap[$name];
        }

        return null;
    }
}
