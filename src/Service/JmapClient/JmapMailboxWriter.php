<?php

declare(strict_types=1);

namespace App\Service\JmapClient;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Exception\JmapApiException;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Service\Label\LabelResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates, renames and destroys mailboxes on a JMAP account's server.
 *
 * One place for Mailbox/set, used by the two handlers that need it: the label
 * structure handler, when a label is made, renamed or deleted in plMail, and
 * the change handler, when a message is filed under a label the server has no
 * mailbox for yet. The second is ApplyGmailLabelsHandler::ensureRemoteLabel()'s
 * job for Gmail, done the same way — the mailbox is made on first use and its
 * id written to the binding at once, so a retry finds it rather than making a
 * second.
 *
 * Unlike Gmail, a JMAP mailbox nests by parentId, so a label's parent is made
 * first and the child is created under it with its own name.
 */
final readonly class JmapMailboxWriter
{
    /** The name a server-side archive is created under when there is none. */
    private const string ARCHIVE_NAME = 'Archive';

    /** The system labels ensure() may create, by LabelRole case: the JMAP role each is made with. */
    private const array CREATABLE_ROLES = [
        'Archive' => 'archive',
        'Trash'   => 'trash',
        'Spam'    => 'junk',
        'Sent'    => 'sent',
        'Drafts'  => 'drafts',
    ];

    public function __construct(
        private JmapClient             $jmap,
        private JmapMailboxSyncer      $mailboxes,
        private LabelResolver          $labelResolver,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * The remote mailbox id a label is filed under, made on the server if it
     * has none yet. Null for a label that has no place on a JMAP server.
     *
     * A system label is created only for a role RFC 8621 names — Archive,
     * Trash, Junk, Sent, Drafts; Snoozed, Unread and All Mail are plMail's
     * alone. A server without an archive mailbox is common, and archiving
     * there would otherwise remove a message from its only mailbox — which
     * RFC 8621 refuses, so the archive would never reach the server and the
     * next sync would put the message back in the Inbox. Trash and the rest
     * were assumed to exist, and on Stalwart and Fastmail they do; another
     * plMail lists a role's mailbox only once something has used it, so
     * deleting here filed nothing there and the next sync undid it.
     *
     * @throws JmapApiException
     */
    public function ensure(Label $label, Account $account): ?string
    {
        $existing = $label->bindingFor($account)?->jmapMailboxId;

        if (null !== $existing) {
            return $existing;
        }

        if (true === $label->isSystem) {
            $jmapRole = self::CREATABLE_ROLES[$label->role->name ?? ''] ?? null;

            if (null === $jmapRole) {
                return null;
            }

            $name = LabelRole::Archive === $label->role ? self::ARCHIVE_NAME : (string) $label->name;

            try {
                return $this->create($label, $account, $name, null, $jmapRole);
            } catch (JmapApiException $refused) {
                // A top-level folder of that name with no role is the usual
                // reason: a client made one as a plain folder, and a sibling
                // may not share its name. That folder is what the user calls
                // it, so it is the one used.
                return $this->adopt($label, $account, $name) ?? throw $refused;
            }
        }

        $parentId = null;

        if (null !== $label->parent) {
            $parentId = $this->ensure($label->parent, $account);
        }

        return $this->create($label, $account, (string) $label->name, $parentId, null);
    }

    /**
     * The remote id of a role's mailbox, made on the server when it has none.
     *
     * @throws JmapApiException
     */
    public function ensureRole(LabelRole $role, Account $account): ?string
    {
        return $this->ensure($this->labelResolver->systemLabel($role, $account), $account);
    }

    /**
     * @throws JmapApiException
     */
    public function rename(Account $account, string $mailboxId, string $name, ?string $parentId): void
    {
        $this->set($account, ['update' => [$mailboxId => ['name' => $name, 'parentId' => $parentId]]], 'updated', $mailboxId);
    }

    /**
     * Destroy a remote mailbox, keeping its mail.
     *
     * onDestroyRemoveEmails false: RFC 8621 then refuses to destroy a mailbox
     * that still holds Emails, which is the answer wanted. Deleting a label in
     * plMail takes the label off its messages; it never deletes the messages,
     * and the server must not either.
     *
     * @throws JmapApiException
     */
    public function destroy(Account $account, string $mailboxId): void
    {
        $this->set($account, ['destroy' => [$mailboxId], 'onDestroyRemoveEmails' => false], 'destroyed', $mailboxId);
    }

    /**
     * @throws JmapApiException
     */
    private function create(Label $label, Account $account, string $name, ?string $parentId, ?string $role): string
    {
        $created = $this->set($account, ['create' => ['new' => array_filter([
            'name'     => $name,
            'parentId' => $parentId,
            'role'     => $role,
        ], static fn (mixed $value): bool => null !== $value)]], 'created', 'new');

        $id = is_string($created['id'] ?? null) ? $created['id'] : null;

        if (null === $id) {
            throw new JmapApiException('JMAP server created a mailbox without an id.');
        }

        // Written at once, before anything else can fail, so a retry finds
        // the mailbox instead of making a second one.
        $this->labelResolver->binding($label, $account)->jmapMailboxId = $id;
        $this->em->flush();

        return $id;
    }

    /**
     * Bind a label to an existing top-level mailbox of this name, if there is one.
     *
     * @throws JmapApiException
     */
    private function adopt(Label $label, Account $account, string $name): ?string
    {
        $list = $this->jmap->call($account, [JmapCapability::MAIL], [
            ['Mailbox/get', ['accountId' => $this->mailboxes->mailAccountId($account), 'ids' => null, 'properties' => ['id', 'name', 'parentId']], 'g'],
        ])->get('g', 'Mailbox/get');

        foreach (is_array($list['list'] ?? null) ? $list['list'] : [] as $mailbox) {
            if (null === ($mailbox['parentId'] ?? null) && 0 === strcasecmp($name, (string) ($mailbox['name'] ?? '')) && true === is_string($mailbox['id'] ?? null)) {
                $this->labelResolver->binding($label, $account)->jmapMailboxId = $mailbox['id'];
                $this->em->flush();

                return $mailbox['id'];
            }
        }

        return null;
    }

    /**
     * One Mailbox/set, with its per-object refusal turned into an exception.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> what the server said about the one object
     *
     * @throws JmapApiException
     */
    private function set(Account $account, array $arguments, string $outcome, string $key): array
    {
        $result = $this->jmap->call($account, [JmapCapability::MAIL], [
            ['Mailbox/set', ['accountId' => $this->mailboxes->mailAccountId($account)] + $arguments, 's'],
        ])->get('s', 'Mailbox/set');

        $refusals = ['created' => 'notCreated', 'updated' => 'notUpdated', 'destroyed' => 'notDestroyed'];
        $refused  = $result[$refusals[$outcome]][$key] ?? null;

        if (true === is_array($refused)) {
            throw new JmapApiException(
                sprintf('JMAP server refused Mailbox/set (%s).', is_string($refused['type'] ?? null) ? $refused['type'] : 'unknown'),
                0,
                is_string($refused['type'] ?? null) ? $refused['type'] : null,
            );
        }

        if ('destroyed' === $outcome) {
            return [];
        }

        $value = $result[$outcome][$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
