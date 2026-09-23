<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MessageEraser;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
 * A folder the listing leaves out is marked missing rather than deleted, and
 * only removed once it has stayed missing for several listings and a grace
 * period — see Mailbox::$missingSince. Deleting the row deletes the mail, so
 * one listing is never enough: not an empty one, not one that lost half the
 * tree, and not one where another client renamed the folder, which is
 * recognised and kept.
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

    /**
     * How many listings in a row have to leave a folder out, and for how long,
     * before its row — and with it, by cascade, every message in it — goes.
     *
     * Both, not either. The count stops one flaky poll from starting the clock
     * and the grace period stops a burst of fast polls from finishing it: a
     * server being restored from backup can answer wrongly five times in five
     * minutes, and it very rarely does so for a day.
     */
    private const int MISSING_SYNCS_BEFORE_REMOVAL = 3;

    private const string MISSING_GRACE = '-24 hours';

    /**
     * A listing that would mark this many stored folders missing at once, and
     * at least this share of them, is not believed at all.
     *
     * The same judgement VanishedMessageReconciler makes about messages, one
     * level up: a user deletes a folder or two, and a server that has lost half
     * its tree between two polls is being rebuilt, not tidied.
     */
    private const int MASS_MISSING_FLOOR = 3;

    private const float MASS_MISSING_RATIO = 0.5;

    public function __construct(
        private MailboxRepository      $mailboxRepository,
        private EntityManagerInterface $em,
        private ImapConnectionFactory  $imapConnectionFactory,
        private LabelResolver          $labelResolver,
        private MessageRepository      $messageRepository,
        private MessageEraser          $eraser,
        private LoggerInterface        $logger,
    ) {}

    /**
     * @return array{created: int, updated: int, deleted: int, renamed: int, missing: int}
     */
    public function syncForAccount(Account $account, ?DateTimeImmutable $now = null): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'renamed' => 0,
            'missing' => 0,
        ];

        if (true === $account->isGmail() || true === $account->isMicrosoft()) {
            return $result;
        }

        $now    = $now ?? new DateTimeImmutable();
        $client = $this->imapConnectionFactory->connect($account);

        try {
            $serverFolders = $this->listFolders($client);

            // Every IMAP account has at least INBOX, so an empty answer is a
            // failed answer. Believing it would mark every folder missing.
            if (0 === count($serverFolders)) {
                $this->logger->warning('Refusing an empty folder listing; no folder is marked or removed', [
                    'accountId' => $account->id,
                ]);

                return $result;
            }

            $specialUses = self::assignSpecialUses($serverFolders);
            $existing    = $this->mailboxRepository->findIndexedByFullPath($account);
            $unmatched   = [];

            foreach ($serverFolders as ['folder' => $folder]) {
                $mailbox = $existing[$folder->path] ?? null;

                if (null === $mailbox) {
                    $unmatched[] = $folder;

                    continue;
                }

                unset($existing[$folder->path]);
                $this->update($mailbox, $folder, $specialUses[$folder->path] ?? null, $account);
                $this->clearMissing($mailbox);
                $result['updated']++;
            }

            // What is left in $existing is every stored folder the server did
            // not name. A new folder that is one of them under a new path is a
            // rename, and keeps its row and its mail.
            foreach ($unmatched as $folder) {
                $specialUse = $specialUses[$folder->path] ?? null;
                $source     = $this->findRenameSource($client, $folder, $specialUse, $existing);

                if (null === $source) {
                    $this->create($account, $folder, $specialUse);
                    $result['created']++;

                    continue;
                }

                $this->logger->info('Folder was renamed on the server; keeping its row', [
                    'accountId' => $account->id,
                    'from'      => $source->fullPath,
                    'to'        => $folder->path,
                ]);

                unset($existing[(string) $source->fullPath]);
                $this->update($source, $folder, $specialUse, $account);
                $this->clearMissing($source);
                $result['renamed']++;
            }

            if (0 < count($existing)) {
                $this->handleMissing($account, $existing, count($serverFolders), $now, $result);
            }

            $this->em->flush();
        } finally {
            $client->disconnect();
        }

        return $result;
    }

    /**
     * @param array<string, Mailbox> $missing
     * @param array{created: int, updated: int, deleted: int, renamed: int, missing: int} $result
     */
    private function handleMissing(Account $account, array $missing, int $listed, DateTimeImmutable $now, array &$result): void
    {
        $stored = count($missing) + $result['updated'] + $result['renamed'];

        if (count($missing) >= self::MASS_MISSING_FLOOR
            && count($missing) >= (int) ceil($stored * self::MASS_MISSING_RATIO)
        ) {
            $this->logger->warning('Refusing a folder listing that leaves out most of the stored folders', [
                'accountId' => $account->id,
                'stored'    => $stored,
                'listed'    => $listed,
                'missing'   => count($missing),
            ]);

            return;
        }

        $graceCutoff = $now->modify(self::MISSING_GRACE);

        foreach ($missing as $mailbox) {
            $mailbox->missingSince ??= $now;
            $mailbox->missingSyncs++;

            if ($mailbox->missingSyncs < self::MISSING_SYNCS_BEFORE_REMOVAL || $mailbox->missingSince > $graceCutoff) {
                $result['missing']++;

                continue;
            }

            $this->remove($mailbox);
            $result['deleted']++;
        }
    }

    /**
     * Take a folder that has stayed gone out of the database, and its mail
     * with it — announced, the way every other removal is.
     *
     * The cascade would delete the messages without a word, and a JMAP client
     * holding their ids would never learn they were gone. MessageEraser logs a
     * destroy for each, and takes their files and thread counters with them.
     */
    private function remove(Mailbox $mailbox): void
    {
        $erased = $this->eraser->eraseAll($this->messageRepository->findBy(['mailbox' => $mailbox]));

        $this->em->remove($mailbox);

        $this->logger->warning('Removed a folder the server has not listed for a while', [
            'mailbox'      => $mailbox->fullPath,
            'missingSince' => $mailbox->missingSince?->format(DATE_ATOM),
            'erased'       => $erased,
        ]);
    }

    private function clearMissing(Mailbox $mailbox): void
    {
        $mailbox->missingSince = null;
        $mailbox->missingSyncs = 0;
    }

    /**
     * The stored folder that a newly listed folder is, under a new name.
     *
     * Two kinds of evidence, both of which have to be unambiguous: the same
     * special use (the Sent folder is still the Sent folder after a rename),
     * or the same UIDVALIDITY — which a rename keeps and a new folder does not,
     * and which is only asked for when there is a missing folder to compare
     * against. One candidate or none; two is a guess this does not make.
     *
     * @param array<string, Mailbox> $missing
     */
    private function findRenameSource(Client $client, Folder $folder, ?MailboxSpecialUse $specialUse, array $missing): ?Mailbox
    {
        if ([] === $missing) {
            return null;
        }

        if (null !== $specialUse) {
            $sameRole = array_filter($missing, static fn (Mailbox $m): bool => $m->specialUse === $specialUse);

            if (1 === count($sameRole)) {
                return reset($sameRole);
            }
        }

        $known = array_filter($missing, static fn (Mailbox $m): bool => null !== $m->uidValidity);

        if ([] === $known) {
            return null;
        }

        try {
            $status = $client->getConnection()->folderStatus($folder->path, ['UIDVALIDITY'])->validatedData();
        } catch (\Throwable) {
            return null;
        }

        $uidValidity = is_array($status) ? ($status['uidvalidity'] ?? null) : null;

        if (null === $uidValidity) {
            return null;
        }

        $sameValidity = array_filter($known, static fn (Mailbox $m): bool => $m->uidValidity === (int) $uidValidity);

        return 1 === count($sameValidity) ? reset($sameValidity) : null;
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
