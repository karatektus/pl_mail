<?php

namespace App\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Account;
use App\Entity\Mailbox;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;

readonly class MailboxSyncer
{
    public function __construct(
        private MailboxRepository      $mailboxRepository,
        private EntityManagerInterface $em,
    ) {}

    public function syncForAccount(Account $account): array
    {
        $client = ImapConnectionFactory::connect($account);

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

            if (isset($existing[$fullPath])) {
                $this->update($existing[$fullPath], $folder);
                $result['updated']++;
            } else {
                $this->create($account, $folder);
                $result['created']++;
            }
        }

        foreach ($existing as $fullPath => $mailbox) {
            if (!in_array($fullPath, $seen, true)) {
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
            $this->detectSpecialUse($folder),
            ['\\Inbox', '\\Junk'],
            true,
        ));
        $this->hydrate($mailbox, $folder);
        $this->em->persist($mailbox);
    }

    private function update(Mailbox $mailbox, Folder $folder): void
    {
        $this->hydrate($mailbox, $folder);
    }

    private function hydrate(Mailbox $mailbox, Folder $folder): void
    {
        $mailbox->setName($folder->name);
        $mailbox->setFullPath($folder->path);
        $mailbox->setDelimiter($folder->delimiter);
        $mailbox->setSpecialUse($this->detectSpecialUse($folder));
    }

    private function detectSpecialUse(Folder $folder): ?string
    {
        $name = strtolower($folder->name);

        $nameMap = [
            'inbox'            => '\\Inbox',
            'sent'             => '\\Sent',
            'sent messages'    => '\\Sent',
            'drafts'           => '\\Drafts',
            'draft'            => '\\Drafts',
            'trash'            => '\\Trash',
            'deleted'          => '\\Trash',
            'deleted messages' => '\\Trash',
            'junk'             => '\\Junk',
            'spam'             => '\\Junk',
            'spambucket'       => '\\Junk',
            'archive'          => '\\Archive',
        ];

        if (array_key_exists($name, $nameMap)) {
            return $nameMap[$name];
        }

        return null;
    }
}
