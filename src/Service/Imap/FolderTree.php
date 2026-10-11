<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\DTO\Mail\FolderTreeRow;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Mailbox;

/**
 * An account's folders as the tree their paths describe, flattened back into
 * the order a list draws it in.
 *
 * IMAP has no parent pointer. A folder's place is its path and the server's
 * delimiter, and the tree is whatever those add up to — which is why this is
 * built here from the rows rather than stored on them.
 *
 * Two things a plain sort by path gets wrong, and this exists for:
 *
 *   - **Order.** "Projects Old" sorts between "Projects" and "Projects/Alpha",
 *     because a space comes before a slash, and a child cut off from its
 *     parent by a stranger is not a tree. Children are grouped under their
 *     parent first and only then sorted among themselves.
 *
 *   - **Parents that are not there.** A server may list "Archive/2024" without
 *     listing "Archive", and a row the server has stopped listing is left out
 *     of what this is given. A folder hangs from its nearest ancestor that IS
 *     in the list, and from the top if there is none, so nothing is indented
 *     under a parent the reader cannot see.
 *
 * The inbox leads its level whatever it is called. On servers that keep
 * everything under it ("INBOX.Sent") that puts it first with the rest beneath;
 * everywhere else it is simply the first row.
 */
final readonly class FolderTree
{
    /**
     * @param iterable<Mailbox> $mailboxes
     *
     * @return list<FolderTreeRow>
     */
    public function rows(iterable $mailboxes): array
    {
        $byPath = [];

        foreach ($mailboxes as $mailbox) {
            $byPath[(string) $mailbox->fullPath] = $mailbox;
        }

        // '' is the top. Every folder is filed under the path of its nearest
        // listed ancestor.
        $children = ['' => []];

        foreach ($byPath as $path => $mailbox) {
            $parent = $this->listedParent($path, $mailbox->delimiter, $byPath);

            $children[$parent][] = $mailbox;
        }

        foreach ($children as &$siblings) {
            usort($siblings, $this->compare(...));
        }

        unset($siblings);

        $rows = [];

        $this->walk('', 0, [], $children, $rows);

        return $rows;
    }

    /**
     * The one row for a folder, in the tree of the folders it is listed with.
     *
     * For the answer to a single switch, which re-renders one row and still
     * has to draw that row's lines.
     *
     * @param iterable<Mailbox> $mailboxes
     */
    public function rowFor(Mailbox $mailbox, iterable $mailboxes): FolderTreeRow
    {
        foreach ($this->rows($mailboxes) as $row) {
            if ($row->mailbox === $mailbox) {
                return $row;
            }
        }

        // Not among them — a folder the server has stopped listing. It still
        // has to render as something: a row at the top, with no lines.
        return new FolderTreeRow($mailbox, 0, true, false, []);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, Mailbox> $byPath
     */
    private function listedParent(string $path, ?string $delimiter, array $byPath): string
    {
        if (null === $delimiter || '' === $delimiter) {
            return '';
        }

        $parent = $path;

        while (false !== $cut = strrpos($parent, $delimiter)) {
            $parent = substr($parent, 0, $cut);

            if (true === isset($byPath[$parent])) {
                return $parent;
            }
        }

        return '';
    }

    private function compare(Mailbox $a, Mailbox $b): int
    {
        $inbox = (MailboxSpecialUse::INBOX === $b->specialUse) <=> (MailboxSpecialUse::INBOX === $a->specialUse);

        return 0 !== $inbox ? $inbox : strcasecmp((string) $a->fullPath, (string) $b->fullPath);
    }

    /**
     * @param list<bool>                    $continue
     * @param array<string, list<Mailbox>>  $children
     * @param list<FolderTreeRow>           $rows
     */
    private function walk(string $parent, int $depth, array $continue, array $children, array &$rows): void
    {
        $siblings = $children[$parent] ?? [];
        $last     = count($siblings) - 1;

        foreach ($siblings as $index => $mailbox) {
            $isLast = $index === $last;
            $path   = (string) $mailbox->fullPath;

            $rows[] = new FolderTreeRow($mailbox, $depth, $isLast, [] !== ($children[$path] ?? []), $continue);

            // The top level draws no line of its own, so it hands none down.
            $this->walk(
                $path,
                $depth + 1,
                0 === $depth ? [] : [...$continue, false === $isLast],
                $children,
                $rows,
            );
        }
    }
}
