<?php

declare(strict_types=1);

namespace App\Service\JmapClient;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Exception\JmapApiException;
use App\Domain\Exception\JmapResyncRequiredException;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Repository\Label\LabelBindingRepository;
use App\Service\Label\LabelResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs a JMAP account's Mailbox list into local Labels.
 *
 * GmailLabelSyncer's job, for JMAP: runs before any Email is read, so every id
 * in an Email's `mailboxIds` has a label to resolve to.
 *
 * ## Mapping
 *
 *   - A mailbox with a role plMail has (inbox, sent, drafts, trash, junk,
 *     archive) is bound to that role's system label.
 *   - Every other mailbox is a custom label at its path, the parent chain
 *     walked by `parentId` — "Work" → "Invoices" is the label Work/Invoices,
 *     exactly as Gmail's "Work/Invoices" and IMAP's "Work.Invoices" are. A
 *     JMAP parent is a mailbox of its own with an id of its own, so it gets
 *     its own binding from its own entry in the list.
 *   - The `all` and `flagged` roles are left alone. They are views a server
 *     may publish as mailboxes, and bound as labels they would put a label on
 *     every message, or a second star on every starred one.
 *
 * ## The whole list, every time anything changed
 *
 * Mailbox/changes is asked first, and only to learn whether there is anything
 * to do: most syncs change nothing, and they cost one small call. When
 * something did change, the whole list is read rather than just the changed
 * ids, because a renamed parent changes the path of every mailbox under it and
 * the path is what a label is found by. A mailbox list is tens of entries, not
 * thousands.
 *
 * ## A mailbox deleted on the server
 *
 * Its binding loses the mailbox id; the label stays. Mail filed under it keeps
 * the label until the server says otherwise about each message — which it
 * does, because deleting a mailbox on a JMAP server moves or destroys its
 * Emails, and each of those is a change the Email sync then reads. Deleting
 * the label here as well would race that and could take the label off mail
 * the server moved somewhere plMail has not heard about yet.
 */
final readonly class JmapMailboxSyncer
{
    /** The type name state is recorded under. See Account::jmapState(). */
    public const string STATE = 'Mailbox';

    /** JMAP role → the system label it is bound to. */
    private const array ROLES = [
        'inbox'   => LabelRole::Inbox,
        'sent'    => LabelRole::Sent,
        'drafts'  => LabelRole::Drafts,
        'trash'   => LabelRole::Trash,
        'junk'    => LabelRole::Spam,
        'archive' => LabelRole::Archive,
    ];

    /** Roles that are views, not places. See the class docblock. */
    private const array VIEW_ROLES = ['all', 'flagged'];

    public function __construct(
        private JmapClient             $jmap,
        private LabelResolver          $labelResolver,
        private LabelBindingRepository $bindings,
        private JmapLabelResolver      $jmapLabels,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * Bring the account's labels in line with its server's mailboxes.
     *
     * @throws JmapApiException
     */
    public function sync(Account $account): void
    {
        $accountId = $this->mailAccountId($account);
        $state     = $account->jmapState(self::STATE);

        if (null !== $state && false === $this->changedSince($account, $accountId, $state)) {
            return;
        }

        $response = $this->jmap->call($account, [JmapCapability::MAIL], [
            ['Mailbox/get', [
                'accountId'  => $accountId,
                'ids'        => null,
                'properties' => ['id', 'name', 'parentId', 'role'],
            ], 'm'],
        ])->get('m', 'Mailbox/get');

        $mailboxes = [];

        foreach (is_array($response['list'] ?? null) ? $response['list'] : [] as $mailbox) {
            if (true === is_array($mailbox) && true === is_string($mailbox['id'] ?? null)) {
                $mailboxes[$mailbox['id']] = $mailbox;
            }
        }

        $bound = [];

        foreach ($mailboxes as $id => $mailbox) {
            // A key, so PHP has made "1" the int 1 — and plMail's own server
            // numbers its mailboxes. Back to the string an id is (RFC 8620).
            $id      = (string) $id;
            $binding = $this->bindingFor($account, $mailbox, $mailboxes);

            if (null === $binding) {
                continue;
            }

            if ($binding->jmapMailboxId !== $id) {
                $binding->jmapMailboxId = $id;
            }

            $bound[$id] = true;
        }

        $released = 0;

        foreach ($this->bindings->findByJmapMailboxIdForAccount($account) as $id => $binding) {
            if (false === isset($bound[$id])) {
                $binding->jmapMailboxId = null;
                ++$released;
            }
        }

        $account->rememberJmapState(self::STATE, is_string($response['state'] ?? null) ? $response['state'] : null);

        $this->em->flush();
        $this->jmapLabels->forget($account);

        $this->logger->info('JmapMailboxSyncer: mailboxes synced', [
            'accountId' => $account->id,
            'bound'     => count($bound),
            'released'  => $released,
        ]);
    }

    /**
     * The JMAP account id this account's mail lives under.
     *
     * Public for every other JMAP mail service, which address the same
     * account and should fail the same way when there is none.
     *
     * @throws JmapApiException when the server offers this user no mail
     */
    public function mailAccountId(Account $account): string
    {
        $accountId = $this->jmap->session($account)->accountFor(JmapCapability::MAIL);

        if (null === $accountId) {
            throw new JmapApiException('The JMAP server offers no mail for this account.');
        }

        return $accountId;
    }

    /**
     * Whether anything about the mailboxes changed since the state we hold.
     *
     * Only the yes-or-no is used — see the class docblock — so the changed
     * ids are not read and `hasMoreChanges` does not matter: any change at
     * all means the whole list is read.
     */
    private function changedSince(Account $account, string $accountId, string $state): bool
    {
        try {
            $changes = $this->jmap->call($account, [JmapCapability::MAIL], [
                ['Mailbox/changes', ['accountId' => $accountId, 'sinceState' => $state], 'c'],
            ])->get('c', 'Mailbox/changes');
        } catch (JmapResyncRequiredException) {
            return true;
        }

        foreach (['created', 'updated', 'destroyed'] as $kind) {
            if ([] !== ($changes[$kind] ?? [])) {
                return true;
            }
        }

        return true === ($changes['hasMoreChanges'] ?? false);
    }

    /**
     * The binding a remote mailbox belongs on, or null for one not modelled.
     *
     * @param array<string, mixed>                $mailbox
     * @param array<string, array<string, mixed>> $all     every mailbox, by id
     */
    private function bindingFor(Account $account, array $mailbox, array $all): ?LabelBinding
    {
        $role = is_string($mailbox['role'] ?? null) ? strtolower($mailbox['role']) : null;

        if (null !== $role && true === in_array($role, self::VIEW_ROLES, true)) {
            return null;
        }

        if (null !== $role && true === array_key_exists($role, self::ROLES)) {
            return $this->labelResolver->binding($this->labelResolver->systemLabel(self::ROLES[$role], $account), $account);
        }

        $label = $this->labelResolver->customChain($this->path($mailbox, $all), $account);

        return null === $label ? null : $this->labelResolver->binding($label, $account);
    }

    /**
     * A mailbox's path from the top, by parent names.
     *
     * Bounded by the number of mailboxes, so a server that reports a parent
     * cycle produces a long name rather than a loop that never ends. A parent
     * with a role contributes its name like any other: a folder inside the
     * Inbox is "Inbox/Receipts" on the server, and plMail's label for it is
     * found by that same path.
     *
     * @param array<string, mixed>                $mailbox
     * @param array<string, array<string, mixed>> $all
     *
     * @return list<string>
     */
    private function path(array $mailbox, array $all): array
    {
        $segments = [(string) ($mailbox['name'] ?? '')];
        $parent   = $mailbox['parentId'] ?? null;
        $guard    = count($all);

        while (true === is_string($parent) && true === isset($all[$parent]) && $guard-- > 0) {
            array_unshift($segments, (string) ($all[$parent]['name'] ?? ''));
            $parent = $all[$parent]['parentId'] ?? null;
        }

        return $segments;
    }
}
