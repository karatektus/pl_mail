<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MailboxRepository;
use App\Service\Label\LabelResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Webklex\PHPIMAP\Client;

/**
 * Makes the folder an outgoing move needs, when the server has not got one.
 *
 * Gmail creates a label on demand — ApplyGmailLabelsHandler::ensureRemoteLabel()
 * — and Graph creates a folder on demand, and IMAP did neither. Nothing in the
 * codebase ever issued a CREATE. The consequences were quiet in the way that
 * takes longest to notice:
 *
 *   - archiving on an account with no Archive folder resolved no destination,
 *     logged "destination not resolvable", and stopped. The message left the
 *     inbox locally, the server never heard, and the next sync put it back.
 *   - a label created in plMail on an IMAP account existed only in plMail,
 *     however long you waited, because a label there *is* a folder and nobody
 *     was making it.
 *
 * ## The namespace is the whole difficulty
 *
 * "Create a folder called Archive" is not a well-defined instruction on IMAP.
 * On a Dovecot server with a top-level namespace it means `Archive`; on the
 * Courier-style servers this project has already had to deal with — the
 * INBOX-prefixed layout the trash-duplication work came from — it means
 * `INBOX.Archive`, and creating `Archive` there produces a folder in a
 * namespace the user's other clients do not show. Get it wrong and the mail
 * moves somewhere nobody can see.
 *
 * Neither the prefix nor the separator is guessed, and neither is hardcoded.
 * Both are read off the folders the account already has, which is the one
 * source that is definitionally right: whatever shape those are in is the shape
 * this server uses. The separator comes from the delimiter the server reported
 * on LIST and stored on the Mailbox row; the prefix from whether the account's
 * existing non-INBOX folders sit under `INBOX<sep>`.
 *
 * A brand-new account with nothing but INBOX is the one case with no evidence.
 * It gets the top-level namespace, which is both the commoner layout and the
 * recoverable mistake — a folder in the wrong namespace is visible and
 * deletable, whereas the alternative is refusing to archive at all.
 *
 * ## Failure is not a crash loop
 *
 * A server that refuses the CREATE — no permission, a strange ACL, a quota —
 * leaves the caller exactly where it was before any of this existed: no
 * destination, one log line, and the move does not happen. It must never throw,
 * because the caller is a Messenger handler whose retry would re-attempt the
 * same rejected CREATE on a schedule.
 */
readonly class ImapFolderProvisioner
{
    /**
     * The folder names to try for each role, in order of how conventional they
     * are. Only used when creating: an existing folder is found by the label
     * binding or by MailboxSyncer's own special-use detection long before this.
     */
    private const array NAMES_BY_ROLE = [
        'archive' => 'Archive',
        'trash'   => 'Trash',
        'spam'    => 'Junk',
        'sent'    => 'Sent',
        'drafts'  => 'Drafts',
    ];

    public function __construct(
        private MailboxRepository      $mailboxes,
        private LabelResolver          $labels,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * The full path of the folder this special use lives at, creating it if the
     * server has not got one.
     *
     * Returns null when the folder could not be made, which the caller must
     * treat exactly as it treated "not resolvable" before.
     */
    public function ensureSpecialUse(
        Account           $account,
        Client            $client,
        MailboxSpecialUse $specialUse,
    ): ?string {
        $existing = $this->mailboxes->findOneBy(['account' => $account, 'specialUse' => $specialUse]);

        if (null !== $existing && null !== $existing->fullPath) {
            return $existing->fullPath;
        }

        $role = LabelRole::fromSpecialUse($specialUse);
        $name = self::NAMES_BY_ROLE[$role->value] ?? null;

        if (null === $name) {
            return null;
        }

        return $this->create($account, $client, $this->pathFor($account, $name), $specialUse);
    }

    /**
     * Create a folder at exactly this path, no namespace reasoning applied.
     *
     * For the case where the path is already known to be right: it came off a
     * Mailbox row the server itself described on an earlier LIST, so its
     * prefix and separator are this server's by construction and rebuilding
     * them could only introduce a difference.
     */
    public function ensureExactPath(Account $account, Client $client, string $path): ?string
    {
        if ('' === $path) {
            return null;
        }

        $known = $this->mailboxes->findOneBy(['account' => $account, 'fullPath' => $path]);

        return $this->create($account, $client, $path, $known?->specialUse, record: null === $known);
    }

    /**
     * The full path for an arbitrary folder name — a custom label being pushed
     * — creating it if need be.
     *
     * The name is a plMail label's full name, so its own "/" separators become
     * the server's, which is what makes a nested label arrive as a nested
     * folder rather than one with a slash in its name.
     */
    public function ensurePath(Account $account, Client $client, string $labelFullName): ?string
    {
        $separator = $this->separatorFor($account);
        $segments  = array_values(array_filter(explode('/', $labelFullName), static fn (string $s): bool => '' !== $s));

        if (0 === count($segments)) {
            return null;
        }

        $path = $this->pathFor($account, implode($separator, $segments));

        $existing = $this->mailboxes->findOneBy(['account' => $account, 'fullPath' => $path]);

        if (null !== $existing) {
            return $path;
        }

        return $this->create($account, $client, $path, null);
    }

    /**
     * Issue the CREATE, subscribe, and record the folder locally.
     *
     * The write-back is the half that stops this from creating a duplicate of
     * its own making. Without a Mailbox row the next MailboxSyncer run meets
     * the folder as one it has never seen — which is harmless for the folder
     * but means the move that prompted all this had no local destination to
     * point at, and the row it moved would sit unlocated until a later sync.
     * Recording it here means the destination exists in both places the moment
     * the CREATE returns, which is what the Gmail and Graph handlers do with
     * the ids they mint.
     */
    private function create(
        Account            $account,
        Client             $client,
        string             $path,
        ?MailboxSpecialUse $specialUse,
        bool               $record = true,
    ): ?string {
        try {
            $client->createFolder($path, expunge: false);

            // Servers differ on whether a created folder is subscribed. An
            // unsubscribed one is invisible in most other clients, so a folder
            // plMail made would be a folder only plMail could see.
            try {
                $client->getConnection()->subscribeFolder($path);
            } catch (Throwable $e) {
                // Worth a line and not worth failing over: the folder exists
                // and the mail can be moved into it either way.
                $this->logger->info('Created an IMAP folder but could not subscribe it', [
                    'account'   => $account->id,
                    'folder'    => $path,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        } catch (Throwable $e) {
            // The caller is a Messenger handler. Throwing here would have it
            // redeliver and re-attempt a CREATE the server has already refused,
            // on a schedule, forever.
            $this->logger->error('Could not create the IMAP folder a move needed', [
                'account'   => $account->id,
                'folder'    => $path,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }

        if (true === $record) {
            $this->record($account, $path, $specialUse);
        }

        $this->logger->info('Created the IMAP folder this account was missing', [
            'account' => $account->id,
            'folder'  => $path,
        ]);

        return $path;
    }

    private function record(Account $account, string $path, ?MailboxSpecialUse $specialUse): void
    {
        $separator = $this->separatorFor($account);
        $segments  = explode($separator, $path);

        $mailbox = new Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = (string) end($segments);
        $mailbox->fullPath      = $path;
        $mailbox->delimiter     = $separator;
        $mailbox->specialUse    = $specialUse;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;

        // Persisted before the binding, for the reason MailboxSyncer::create()
        // gives: LabelResolver flushes to mint the binding id, and the mailbox
        // has to be managed by then or the binding's FK points at nothing.
        $this->em->persist($mailbox);

        $label = null !== $specialUse
            ? $this->labels->systemLabel(LabelRole::fromSpecialUse($specialUse), $account)
            : $this->labels->customChain($segments, $account);

        if (null !== $label) {
            $this->labels->bindMailbox($label, $mailbox);
        }

        $this->em->flush();
    }

    /**
     * Where a new folder belongs in this server's namespace.
     *
     * Read off what the account already has rather than configured or guessed.
     * A server whose folders all sit under `INBOX.` gets `INBOX.Archive`; one
     * with top-level folders gets `Archive`.
     */
    private function pathFor(Account $account, string $name): string
    {
        $prefix = $this->prefixFor($account);

        return $prefix . $name;
    }

    private function prefixFor(Account $account): string
    {
        $separator = $this->separatorFor($account);

        foreach ($this->mailboxes->findBy(['account' => $account]) as $mailbox) {
            $path = (string) $mailbox->fullPath;

            if ('' === $path || 0 === strcasecmp($path, 'INBOX')) {
                continue;
            }

            // One folder that is not under INBOX is enough to settle it: a
            // server with a personal namespace at the top level has no reason
            // to put anything under INBOX at all.
            if (false === str_starts_with($path, 'INBOX' . $separator)) {
                return '';
            }
        }

        // Either every non-INBOX folder is under INBOX., or there are none. The
        // second is a fresh account with no evidence either way, and the top
        // level is both commoner and the recoverable mistake.
        return $this->hasFoldersBesidesInbox($account)
            ? 'INBOX' . $separator
            : '';
    }

    private function hasFoldersBesidesInbox(Account $account): bool
    {
        foreach ($this->mailboxes->findBy(['account' => $account]) as $mailbox) {
            if (0 !== strcasecmp((string) $mailbox->fullPath, 'INBOX')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The server's hierarchy separator, as it reported it on LIST.
     *
     * "." and "/" are both common and neither is safe to assume. INBOX is
     * consulted last rather than first because some servers report no delimiter
     * for it at all.
     */
    private function separatorFor(Account $account): string
    {
        $fallback = null;

        foreach ($this->mailboxes->findBy(['account' => $account]) as $mailbox) {
            $delimiter = $mailbox->delimiter;

            if (null === $delimiter || '' === $delimiter) {
                continue;
            }

            if (0 === strcasecmp((string) $mailbox->fullPath, 'INBOX')) {
                $fallback = $delimiter;

                continue;
            }

            return $delimiter;
        }

        return $fallback ?? '.';
    }
}
