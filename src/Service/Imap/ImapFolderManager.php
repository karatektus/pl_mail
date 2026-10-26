<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Exception\ImapFolderRefusedException;
use App\Domain\Exception\InvalidFolderNameException;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MailboxRepository;
use App\Service\Label\LabelNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

/**
 * New folder on a plain IMAP account — folders as places.
 *
 * Step 1 of #221. A folder is where a message is, one per message per account,
 * and these are the actions on the place itself. They mirror the account's
 * real tree: the person picks the parent among the folders the server listed,
 * the separator is the one LIST reported for that parent, and the top level is
 * the server's personal namespace (RFC 2342), so plMail never invents a layout
 * the account's other clients would not show.
 *
 * Each action says exactly what happened or why it did not: a name that cannot
 * be a folder is refused before the server is asked, and the server's refusal
 * (ImapFolderRefusedException) comes back in its own words.
 *
 * Labels are touched only as far as a folder already is one in the sidebar: a
 * new folder gets its label the way a sync would give it one.
 */
final readonly class ImapFolderManager
{
    public function __construct(
        private ImapConnectionFactory   $connections,
        private ImapFolderProvisioner   $provisioner,
        private MailboxRepository       $mailboxes,
        private LabelNotifier           $labelNotifier,
        private UrlGeneratorInterface   $urls,
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

    // ---------------------------------------------------------------- helpers

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
