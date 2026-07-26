<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Domain\Enum\LabelRole;
use App\Entity\Label;

/**
 * Maps a plMail Label onto a JMAP Mailbox object (RFC 8621 §2). JMAP models
 * hierarchy via parentId, so "name" is the leaf name (Label::$name), not the
 * full path.
 */
final class MailboxMapper
{
    /**
     * @return array<string,mixed>
     */
    public function toJmap(Label $label): array
    {
        $counts = $this->counts($label);

        return [
            'id' => (string) $label->id,
            'name' => (string) $label->name,
            'parentId' => $this->parentId($label),
            'role' => $this->roleOf($label),
            'sortOrder' => $label->sortOrder ?? 0,
            'totalEmails' => $counts['totalEmails'],
            'unreadEmails' => $counts['unreadEmails'],
            'totalThreads' => $counts['totalThreads'],
            'unreadThreads' => $counts['unreadThreads'],
            'myRights' => $this->rights($label),
            'isSubscribed' => $label->isVisible,
        ];
    }

    /**
     * @param list<string>|null $properties
     *
     * @return array<string,mixed>
     */
    public function toJmapWithProperties(Label $label, ?array $properties): array
    {
        $full = $this->toJmap($label);

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

    public function parentId(Label $label): ?string
    {
        if (null === $label->parent) {
            return null;
        }

        return (string) $label->parent->id;
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

    /**
     * @return array{totalEmails:int,unreadEmails:int,totalThreads:int,unreadThreads:int}
     */
    private function counts(Label $label): array
    {
        // Phase 4: replace with a grouped COUNT over the message<->label join
        // (total + unread, plus thread-collapsed variants). Zero is a valid
        // placeholder that lets clients render the folder tree in the meantime.
        return [
            'totalEmails' => 0,
            'unreadEmails' => 0,
            'totalThreads' => 0,
            'unreadThreads' => 0,
        ];
    }
}
