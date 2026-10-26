<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Enum\Mail\SyncTrigger;
use App\Domain\Exception\FolderActionRefusedException;
use App\Domain\Exception\ImapFolderRefusedException;
use App\Domain\Exception\InvalidFolderNameException;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Label\LabelNotifier;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MailChangeRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

/**
 * New folder, rename and delete on a plain IMAP account — folders as places.
 *
 * Step 1 of #221. A folder is where a message is, one per message per account,
 * and these are the actions on the place itself. They mirror the account's
 * real tree: the person picks the parent among the folders the server listed,
 * the separator is the one LIST reported for that parent, and the top level is
 * the server's personal namespace (RFC 2342), so plMail never invents a layout
 * the account's other clients would not show.
 *
 * Each action says exactly what happened or why it did not. plMail's own rules
 * (InvalidFolderNameException, FolderActionRefusedException) are checked
 * before the server is asked; the server's refusal (ImapFolderRefusedException)
 * comes back in its own words.
 *
 * Labels are touched only as far as a folder already is one in the sidebar:
 * a new folder gets its label the way a sync would give it one, a renamed
 * folder keeps its label under the new name, and a deleted folder's label goes
 * only when nothing else is left on it.
 */
final readonly class ImapFolderManager
{
    public function __construct(
        private ImapConnectionFactory   $connections,
        private ImapFolderProvisioner   $provisioner,
        private MailboxRepository       $mailboxes,
        private MessageRepository       $messages,
        private MessageThreadRepository $threads,
        private LabelResolver           $labelResolver,
        private MailChangeRecorder      $changes,
        private LabelNotifier           $labelNotifier,
        private MessageBusInterface     $bus,
        private UrlGeneratorInterface   $urls,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {
    }

    /**
     * Make a folder called $name inside $parent, or at the top of the
     * account's personal namespace when $parent is null.
     *
     * @throws InvalidFolderNameException  before the server is asked
     * @throws ImapFolderRefusedException  with the server's reason
     */
    public function create(Account $account, ?Mailbox $parent, string $name): Mailbox
    {
        // Checked before connecting, so a name that cannot be a folder costs
        // no round trip. The top level is checked again below against the
        // namespace's own separator, which only the server can say.
        ImapFolderName::validate($name, $this->separatorOf($parent, $account), null === $parent);

        $client = $this->connections->connect($account);

        try {
            if (null !== $parent) {
                $path = ImapFolderName::childPath(
                    (string) $parent->fullPath,
                    $this->separatorOf($parent, $account),
                    $name,
                );
            } else {
                [$prefix, $separator] = $this->personalNamespace($client, $account);

                // A Courier-style server keeps everything under "INBOX.", and
                // its top level is that prefix: a folder created beside INBOX
                // there lands in a namespace no other client shows, if the
                // server accepts it at all.
                $path = ImapFolderName::childPath(
                    '' === $prefix ? null : rtrim($prefix, $separator),
                    $separator,
                    $name,
                );
            }

            $mailbox = $this->provisioner->createFolder($account, $client, $path);
        } finally {
            $this->disconnect($client);
        }

        $this->logger->info('Created an IMAP folder on request', ['account' => $account->id, 'folder' => $path]);
        $this->labelsChanged($account);

        return $mailbox;
    }

    /**
     * Give a folder a new name in the same place, with IMAP RENAME.
     *
     * The server renames the folders inside it too (RFC 3501 §6.3.5), so their
     * rows follow here at once; otherwise the next sync would meet them as new
     * folders and the old ones as missing. A server may give the renamed
     * folders a new UIDVALIDITY, so a sync is asked for straight after, and
     * VanishedMessageReconciler re-reads a folder whose UIDVALIDITY changed.
     *
     * @throws FolderActionRefusedException for the inbox and the other special-use folders
     * @throws InvalidFolderNameException
     * @throws ImapFolderRefusedException
     */
    public function rename(Account $account, Mailbox $mailbox, string $name): Mailbox
    {
        $this->refuseSpecialUse($mailbox);

        $separator = $this->separatorOf($mailbox, $account);
        $oldPath   = (string) $mailbox->fullPath;
        $cut       = strrpos($oldPath, $separator);
        $newPath   = ImapFolderName::childPath(false === $cut ? null : substr($oldPath, 0, $cut), $separator, $name);

        if ($newPath === $oldPath) {
            return $mailbox;
        }

        $client = $this->connections->connect($account);

        try {
            $connection = $client->getConnection();

            try {
                // Raw paths both ways, for the reason ImapFolderProvisioner
                // gives: they are modified UTF-7 already, and Folder::rename()
                // would encode the new one again.
                $connection->renameFolder($oldPath, $newPath)->validatedData();
            } catch (Throwable $e) {
                throw ImapFolderRefusedException::fromServer($oldPath, $e);
            }

            // Subscriptions are by name, and some servers do not move them.
            try {
                $connection->unsubscribeFolder($oldPath);
                $connection->subscribeFolder($newPath);
            } catch (Throwable $e) {
                $this->logger->info('Could not move the subscription of a renamed IMAP folder', [
                    'account' => $account->id,
                    'folder'  => $newPath,
                    'error'   => $e->getMessage(),
                ]);
            }
        } finally {
            $this->disconnect($client);
        }

        foreach ($this->descendantsOf($account, $mailbox) as $child) {
            $child->fullPath = $newPath . substr((string) $child->fullPath, strlen($oldPath));
        }

        $mailbox->fullPath = $newPath;
        $mailbox->name     = ImapFolderName::validate($name, $separator, false);

        // The folder moved, so its label follows: renamed where it stands when
        // it is this folder's alone (LabelResolver::linkFolder()).
        $this->labelResolver->linkFolder($mailbox, $account, $oldPath);

        $this->em->flush();

        $this->logger->info('Renamed an IMAP folder on request', [
            'account' => $account->id,
            'from'    => $oldPath,
            'to'      => $newPath,
        ]);

        $this->bus->dispatch(new SyncAccountMessage((int) $account->id, SyncTrigger::Manual));
        $this->labelsChanged($account);

        return $mailbox;
    }

    /**
     * Delete a folder that is empty, here and on the server, and has no
     * folders inside it.
     *
     * @throws FolderActionRefusedException
     * @throws ImapFolderRefusedException
     */
    public function delete(Account $account, Mailbox $mailbox): void
    {
        $this->refuseSpecialUse($mailbox);

        if ([] !== $this->descendantsOf($account, $mailbox)) {
            throw new FolderActionRefusedException(FolderActionRefusedException::HAS_CHILDREN);
        }

        $local = $this->messages->countInMailbox($mailbox);

        if (0 < $local) {
            throw new FolderActionRefusedException(FolderActionRefusedException::NOT_EMPTY, $local);
        }

        $path   = (string) $mailbox->fullPath;
        $client = $this->connections->connect($account);

        try {
            $connection = $client->getConnection();

            // Asked of the server as well: mail plMail has not imported, or
            // that arrived since the last sync, is still mail.
            try {
                $status = $connection->folderStatus($path, ['MESSAGES'])->validatedData();
            } catch (Throwable $e) {
                throw ImapFolderRefusedException::fromServer($path, $e);
            }

            $remote = is_array($status) ? (int) ($status['messages'] ?? 0) : 0;

            if (0 < $remote) {
                throw new FolderActionRefusedException(FolderActionRefusedException::NOT_EMPTY, $remote);
            }

            try {
                $connection->deleteFolder($path)->validatedData();
            } catch (Throwable $e) {
                throw ImapFolderRefusedException::fromServer($path, $e);
            }

            try {
                $connection->unsubscribeFolder($path);
            } catch (Throwable) {
                // The folder is gone either way; see RemoteLabelRemover.
            }
        } finally {
            $this->disconnect($client);
        }

        // Counted again after the round trip, as RemoteLabelRemover does:
        // message.mailbox_id cascades, and a sync may have filed something
        // here in the meantime.
        $arrived = $this->messages->countInMailbox($mailbox);

        if (0 < $arrived) {
            throw new FolderActionRefusedException(FolderActionRefusedException::NOT_EMPTY, $arrived);
        }

        $binding = $mailbox->labelBinding;
        $label   = $binding?->label;

        if (null !== $binding) {
            $binding->mailbox      = null;
            $mailbox->labelBinding = null;
        }

        $this->em->remove($mailbox);
        $this->em->flush();

        if (null !== $label && true === $this->isLeftOver($label)) {
            $this->changes->labelDeleted($label);
            $this->em->remove($label);
            $this->em->flush();
        }

        $this->logger->info('Deleted an empty IMAP folder on request', ['account' => $account->id, 'folder' => $path]);
        $this->labelsChanged($account);
    }

    /**
     * The personal namespace's prefix and separator (RFC 2342), or the
     * evidence of the folders the account already has when the server does
     * not answer NAMESPACE.
     *
     * @return array{0: string, 1: string}
     */
    public function personalNamespace(Client $client, Account $account): array
    {
        $fallback = ['', $this->separatorOf(null, $account)];
        $protocol = $client->getConnection();

        if (false === $protocol instanceof ImapProtocol) {
            return $fallback;
        }

        try {
            $lines = $protocol->requestAndResponse('NAMESPACE')->data();
        } catch (Throwable $e) {
            $this->logger->info('The IMAP server did not answer NAMESPACE', [
                'account' => $account->id,
                'error'   => $e->getMessage(),
            ]);

            return $fallback;
        }

        foreach ((array) $lines as $line) {
            if (false === is_array($line)) {
                continue;
            }

            $at = array_search('NAMESPACE', $line, true);

            // * NAMESPACE (("" "/")) NIL NIL — the first list after the word
            // is the personal namespaces, and the first of those is the one.
            $personal = false === $at ? null : ($line[$at + 1] ?? null);
            $first    = is_array($personal) ? ($personal[0] ?? null) : null;

            if (is_array($first) && isset($first[0], $first[1])) {
                $prefix    = trim((string) $first[0], '"');
                $separator = trim((string) $first[1], '"');

                return [$prefix, '' === $separator || 'NIL' === $separator ? $fallback[1] : $separator];
            }
        }

        return $fallback;
    }

    /**
     * The folders inside this one, by path.
     *
     * @return list<Mailbox>
     */
    public function descendantsOf(Account $account, Mailbox $mailbox): array
    {
        $prefix = (string) $mailbox->fullPath . $this->separatorOf($mailbox, $account);

        return array_values(array_filter(
            $this->mailboxes->findBy(['account' => $account]),
            static fn (Mailbox $m): bool => str_starts_with((string) $m->fullPath, $prefix),
        ));
    }

    /**
     * Whether plMail keeps its hands off this folder: the inbox and every
     * folder the server or the syncer gave a special use.
     */
    public static function isProtected(Mailbox $mailbox): bool
    {
        return null !== $mailbox->specialUse || 0 === strcasecmp((string) $mailbox->fullPath, 'INBOX');
    }

    // ---------------------------------------------------------------- helpers

    private function refuseSpecialUse(Mailbox $mailbox): void
    {
        if (true === self::isProtected($mailbox)) {
            throw new FolderActionRefusedException(FolderActionRefusedException::SPECIAL_USE);
        }
    }

    /**
     * A label with nothing left on it: no folder or provider id on any
     * account, no nested labels, and no conversation carrying it as a tag.
     */
    private function isLeftOver(Label $label): bool
    {
        if (null !== $label->role || false === $label->children->isEmpty()) {
            return false;
        }

        foreach ($label->bindings as $binding) {
            if (null !== $binding->mailbox
                || null !== $binding->gmailLabelId
                || null !== $binding->graphFolderId
                || null !== $binding->graphCategoryId
            ) {
                return false;
            }
        }

        return 0 === $this->threads->countForLabel($label);
    }

    /**
     * The separator LIST reported for this folder, or for the account's other
     * folders when this one carried none.
     */
    private function separatorOf(?Mailbox $mailbox, Account $account): string
    {
        $delimiter = $mailbox?->delimiter;

        if (null !== $delimiter && '' !== $delimiter) {
            return $delimiter;
        }

        foreach ($this->mailboxes->findBy(['account' => $account]) as $other) {
            if (null !== $other->delimiter && '' !== $other->delimiter) {
                return $other->delimiter;
            }
        }

        return '/';
    }

    private function labelsChanged(Account $account): void
    {
        $user = $account->usr;

        if (null !== $user) {
            $this->labelNotifier->publishLabelsStale($user, $this->urls->generate('app_label_lists'));
        }
    }

    private function disconnect(Client $client): void
    {
        try {
            $client->disconnect();
        } catch (Throwable) {
        }
    }
}
