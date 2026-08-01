<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;

/**
 * Maps a plMail LabelBinding onto a JMAP Mailbox object (RFC 8621 §2). JMAP
 * models hierarchy via parentId, so "name" is the leaf name (Label::$name),
 * not the full path.
 *
 * The JMAP id is the BINDING id, not the label id. A JMAP account is a plMail
 * Account, and labels are user-scoped — so the per-account binding is the
 * thing that has a stable identity within one JMAP account. Counts are still
 * keyed by label id, since that is what the message_label join stores.
 */
final class MailboxMapper
{
    /**
     * @param array<int,int> $bindingIdByLabelId  label id => binding id, for
     *        this account; used to express parentId in the binding id space
     *
     * @return array<string,mixed>
     */
    public function toJmap(LabelBinding $binding, MailboxCounts $counts, array $bindingIdByLabelId = []): array
    {
        $label    = $binding->label;
        $resolved = $counts->forLabel($label->id);

        return [
            'id' => (string) $binding->id,
            // plMail extension. The binding id is per-account by necessity, so
            // the same label reachable from three accounts is three Mailboxes
            // with three ids and nothing tying them together. A client showing
            // one unified sidebar needs that link, and matching on name breaks
            // the moment a label is renamed in one account. Unknown properties
            // are ignored by spec-conformant clients, so this is additive.
            'labelId' => (string) $label->id,
            // plMail extension, like labelId above. RFC 8621 gives Mailbox no
            // colour, but the label has one and every client draws chips with
            // it — without this the phone can only draw them grey while the web
            // draws them coloured, which reads as two different label systems.
            //
            // A Tailwind token, not hex: see LabelColor. Null is a label with
            // no colour chosen, which is distinct from one coloured grey.
            'color' => $label->color,
            'name' => (string) $label->name,
            'parentId' => $this->parentId($label, $bindingIdByLabelId),
            'role' => $this->roleOf($label),
            'sortOrder' => $label->sortOrder ?? 0,
            'totalEmails' => $resolved['totalEmails'],
            'unreadEmails' => $resolved['unreadEmails'],
            'totalThreads' => $resolved['totalThreads'],
            'unreadThreads' => $resolved['unreadThreads'],
            'myRights' => $this->rights($label),
            'isSubscribed' => $label->isVisible,
        ];
    }

    /**
     * @param list<string>|null $properties
     * @param array<int,int>    $bindingIdByLabelId
     *
     * @return array<string,mixed>
     */
    public function toJmapWithProperties(LabelBinding $binding, MailboxCounts $counts, ?array $properties, array $bindingIdByLabelId = []): array
    {
        $full = $this->toJmap($binding, $counts, $bindingIdByLabelId);

        if (null === $properties) {
            return $full;
        }

        // "id" is always returned regardless of the requested property set.
        $filtered = ['id' => $full['id']];

        foreach ($properties as $property) {
            if (true === array_key_exists($property, $full)) {
                $filtered[$property] = $full[$property];
            }
        }

        return $filtered;
    }

    /**
     * The parent expressed as a binding id. A parent the account has no
     * binding for is not a visible Mailbox there, so the child reports as
     * top-level rather than pointing at an id the client cannot resolve.
     *
     * @param array<int,int> $bindingIdByLabelId
     */
    public function parentId(Label $label, array $bindingIdByLabelId = []): ?string
    {
        $parent = $label->parent;

        if (null === $parent) {
            return null;
        }

        $parentBindingId = $bindingIdByLabelId[(int) $parent->id] ?? null;

        if (null === $parentBindingId) {
            return null;
        }

        return (string) $parentBindingId;
    }

    /**
     * plMail LabelRole -> JMAP role (RFC 8621 §2). Matching on the case *name*
     * with a null default means an unmapped or renamed role degrades to "no
     * role" (the mailbox still appears) instead of failing. ALIGN THESE ARMS
     * with your actual LabelRole cases.
     */
    public function roleOf(Label $label): ?string
    {
        if (null === $label->role) {
            return null;
        }

        return match ($label->role->name) {
            'Inbox' => 'inbox',
            'Sent' => 'sent',
            'Drafts' => 'drafts',
            'Trash', 'Bin', 'Deleted' => 'trash',
            'Spam', 'Junk' => 'junk',
            'Archive' => 'archive',
            'Starred', 'Flagged' => 'flagged',
            'Important' => 'important',
            'All', 'AllMail' => 'all',
            // Vendor role: there is no IANA special-use for snoozed, so this
            // is plMail's own. A standard client reads an unrecognised role
            // exactly as this method's default does — no role, mailbox still
            // listed — which is the intended degradation.
            'Snoozed' => 'snoozed',
            default => null,
        };
    }

    /**
     * @return array<string,bool>
     */
    private function rights(Label $label): array
    {
        // System labels (role !== null) may not be renamed or deleted; custom
        // labels are fully mutable.
        $mutable = false === $label->isSystem;

        return [
            'mayReadItems' => true,
            'mayAddItems' => true,
            'mayRemoveItems' => true,
            'maySetSeen' => true,
            'maySetKeywords' => true,
            'mayCreateChild' => true,
            'mayRename' => $mutable,
            'mayDelete' => $mutable,
            'maySubmit' => true,
        ];
    }
}
