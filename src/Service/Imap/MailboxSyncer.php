<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MailboxRepository;
use App\Service\Label\LabelResolver;
use Doctrine\ORM\EntityManagerInterface;
use Webklex\PHPIMAP\Client;
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
    /**
     * RFC 6154 special-use attributes, as the server's LIST reports them.
     *
     * The server's word outranks any name: it is how Dovecot, Gmail and
     * Exchange say which folder is Sent in whatever language the user picked,
     * and it is the only answer that stays right when the folder is called
     * "Gesendet" or "Sent Items".
     */
    private const array SPECIAL_USE_ATTRIBUTES = [
        '\\sent'    => MailboxSpecialUse::SENT,
        '\\trash'   => MailboxSpecialUse::TRASH,
        '\\junk'    => MailboxSpecialUse::JUNK,
        '\\drafts'  => MailboxSpecialUse::DRAFTS,
        '\\archive' => MailboxSpecialUse::ARCHIVE,
    ];

    /**
     * Names a folder is recognised by when the server did not say.
     *
     * Plenty of servers do not: an old Courier, a provider that never
     * implemented SPECIAL-USE, one that only reports it to `LIST … RETURN
     * (SPECIAL-USE)`. Matched against the decoded leaf name, lower-cased — so
     * this list is where "Gesendet" (GMX, web.de, T-Online), "Sent Items" and
     * "Deleted Items" (Exchange) and "Junk E-mail" (Outlook) live. Without them
     * the send path found no Sent folder on those accounts and skipped the
     * APPEND without a word.
     */
    private const array SPECIAL_USE_NAMES = [
        'sent'               => MailboxSpecialUse::SENT,
        'sent messages'      => MailboxSpecialUse::SENT,
        'sent items'         => MailboxSpecialUse::SENT,
        'sent mail'          => MailboxSpecialUse::SENT,
        'gesendet'           => MailboxSpecialUse::SENT,
        'gesendete objekte'  => MailboxSpecialUse::SENT,
        'gesendete elemente' => MailboxSpecialUse::SENT,
        'drafts'             => MailboxSpecialUse::DRAFTS,
        'draft'              => MailboxSpecialUse::DRAFTS,
        'entwürfe'           => MailboxSpecialUse::DRAFTS,
        'entwurf'            => MailboxSpecialUse::DRAFTS,
        'trash'              => MailboxSpecialUse::TRASH,
        'deleted'            => MailboxSpecialUse::TRASH,
        'deleted messages'   => MailboxSpecialUse::TRASH,
        'deleted items'      => MailboxSpecialUse::TRASH,
        'bin'                => MailboxSpecialUse::TRASH,
        'papierkorb'         => MailboxSpecialUse::TRASH,
        'gelöscht'           => MailboxSpecialUse::TRASH,
        'gelöschte objekte'  => MailboxSpecialUse::TRASH,
        'gelöschte elemente' => MailboxSpecialUse::TRASH,
        'junk'               => MailboxSpecialUse::JUNK,
        'junk e-mail'        => MailboxSpecialUse::JUNK,
        'junk email'         => MailboxSpecialUse::JUNK,
        'junk-e-mail'        => MailboxSpecialUse::JUNK,
        'spam'               => MailboxSpecialUse::JUNK,
        'spamverdacht'       => MailboxSpecialUse::JUNK,
        'spambucket'         => MailboxSpecialUse::JUNK,
        'archive'            => MailboxSpecialUse::ARCHIVE,
        'archiv'             => MailboxSpecialUse::ARCHIVE,
    ];

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

        $serverFolders = $this->listFolders($client);
        $specialUses   = self::assignSpecialUses($serverFolders);

        $existing = $this->mailboxRepository->findIndexedByFullPath($account);

        $result = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
        ];
        $seen = [];

        foreach ($serverFolders as ['folder' => $folder]) {
            $fullPath   = $folder->path;
            $seen[]     = $fullPath;
            $specialUse = $specialUses[$fullPath] ?? null;

            if (true === isset($existing[$fullPath])) {
                $this->update($existing[$fullPath], $folder, $specialUse, $account);
                $result['updated']++;
            } else {
                $this->create($account, $folder, $specialUse);
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

    /**
     * The folder list, each folder with the attributes its LIST line carried.
     *
     * Asked of the connection rather than through Client::getFolders(), which
     * builds the same Folder objects from the same response and then drops the
     * attribute list: Folder keeps \NoSelect and \HasChildren as booleans and
     * nothing else, so the \Sent the server sent is gone by the time anything
     * could read it. It is one LIST either way.
     *
     * An empty answer comes back empty here rather than as the library's
     * FolderFetchingException; the caller decides what an empty list means.
     *
     * @return list<array{folder: Folder, attributes: list<string>}>
     */
    private function listFolders(Client $client): array
    {
        $items = $client->getConnection()->folders('', '*')->validatedData();

        $folders = [];

        foreach ((array) $items as $path => $item) {
            $attributes = array_values(array_map('strval', (array) ($item['flags'] ?? [])));

            $folders[] = [
                'folder'     => new Folder($client, (string) $path, (string) ($item['delimiter'] ?? '.'), $attributes),
                'attributes' => $attributes,
            ];
        }

        return $folders;
    }

    /**
     * Which folder plays which role, decided over the whole listing at once.
     *
     * Attributes first, names second — and a role the server gave out by
     * attribute is not handed out again by name. A Dovecot account with a
     * \Sent "Sent" and a leftover "Gesendet" from an import has exactly one
     * Sent folder, the one the server named; a second folder bound to the same
     * system label would fight the first for it.
     *
     * Public and static because it is the whole of the rule, and a test should
     * be able to hand it a listing without standing up a syncer.
     *
     * @param list<array{folder: Folder, attributes: list<string>}> $folders
     *
     * @return array<string, MailboxSpecialUse> keyed by the folder's raw path
     */
    public static function assignSpecialUses(array $folders): array
    {
        $assigned = [];
        $claimed  = [];

        foreach ($folders as ['folder' => $folder, 'attributes' => $attributes]) {
            $role = self::specialUseFromAttributes($attributes);

            if (null === $role) {
                continue;
            }

            $assigned[$folder->path] = $role;
            $claimed[$role->value]   = true;
        }

        foreach ($folders as ['folder' => $folder]) {
            if (true === isset($assigned[$folder->path])) {
                continue;
            }

            $role = self::specialUseFromName($folder);

            if (null === $role || true === isset($claimed[$role->value])) {
                continue;
            }

            $assigned[$folder->path] = $role;
        }

        return $assigned;
    }

    /**
     * @param list<string> $attributes
     */
    private static function specialUseFromAttributes(array $attributes): ?MailboxSpecialUse
    {
        foreach ($attributes as $attribute) {
            $role = self::SPECIAL_USE_ATTRIBUTES[strtolower($attribute)] ?? null;

            if (null !== $role) {
                return $role;
            }
        }

        return null;
    }

    private static function specialUseFromName(Folder $folder): ?MailboxSpecialUse
    {
        // INBOX is special by protocol rather than by name: RFC 3501 reserves
        // it, case-insensitively, as the path itself.
        if ('inbox' === strtolower($folder->path)) {
            return MailboxSpecialUse::INBOX;
        }

        return self::SPECIAL_USE_NAMES[mb_strtolower(trim($folder->name))] ?? null;
    }

    private function create(Account $account, Folder $folder, ?MailboxSpecialUse $specialUse): void
    {
        $mailbox = new Mailbox();
        $mailbox->account = $account;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = in_array(
            $specialUse?->value,
            ['\\Inbox', '\\Junk'],
            true,
        );
        // Persist before hydrate: hydrate() binds the folder to a label, and
        // LabelResolver flushes to mint the binding id — the mailbox has to be
        // managed by then or the binding's FK points at nothing. Every
        // non-nullable column is set inside hydrate() before that flush.
        $this->em->persist($mailbox);
        $this->hydrate($mailbox, $folder, $specialUse, $account);
    }

    private function update(Mailbox $mailbox, Folder $folder, ?MailboxSpecialUse $specialUse, Account $account): void
    {
        $this->hydrate($mailbox, $folder, $specialUse, $account);
    }

    private function hydrate(Mailbox $mailbox, Folder $folder, ?MailboxSpecialUse $specialUse, Account $account): void
    {
        $mailbox->name = $folder->name;
        $mailbox->fullPath = $folder->path;
        $mailbox->delimiter = $folder->delimiter;
        $mailbox->specialUse = $specialUse;

        $this->linkLabel($mailbox, $account);
    }

    private function linkLabel(Mailbox $mailbox, Account $account): void
    {
        $specialUse = $mailbox->specialUse;

        if (null !== $specialUse) {
            $this->labelResolver->bindMailbox(
                $this->labelResolver->systemLabel(LabelRole::fromSpecialUse($specialUse), $account),
                $mailbox,
            );

            return;
        }

        $segments = $this->labelResolver->segmentsFromImapPath(
            (string) $mailbox->fullPath,
            $mailbox->delimiter,
        );

        if (count($segments) === 0) {
            $segments = [(string) $mailbox->name];
        }

        $label = $this->labelResolver->customChain($segments, $account);

        if (null !== $label) {
            $this->labelResolver->bindMailbox($label, $mailbox);
        }
    }
}
