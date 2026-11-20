<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Service\Mail\SidebarCounts;

/**
 * The folders under an account in the sidebar, as the tree the server has
 * (#221, Adrian's report on v0.4.1).
 *
 * The rows used to be a flat list indented by Label::$depth, which is the
 * depth in plMail's label tree. That tree is not the account's folder tree:
 * labels are per user and shared by every account, and
 * LabelResolver::segmentsFromImapPath() drops a leading INBOX, so
 * `INBOX/1_test/2_test` became a top-level `1_test` beside the Inbox while
 * Thunderbird showed it inside the Inbox. The rows were also ordered by
 * sortOrder and then by the label's own name, which nothing kept beside its
 * parent.
 *
 * On a plain IMAP account the tree is read off the folders' own paths: a row's
 * parent is the nearest folder whose path is a prefix of its own, split on the
 * server's delimiter. So a folder under INBOX is under the Inbox row, on every
 * layout, because that is where the server and every other client put it.
 * Elsewhere (Gmail, Outlook, JMAP) there is no path to read, and the parent is
 * the nearest label up the label tree that is a row here too.
 *
 * Siblings keep the order the rows came in (LabelRepository::findBoundToAccount()):
 * system folders first, then by name.
 *
 * With labels listed by account (User::$labelsByAccount, #690) the rows are
 * every label on the account, its tags as well as its places, so the account
 * shows everything it has the way its own webmail would. A tag has no folder
 * path, so on plain IMAP it hangs off its label parent like a row elsewhere
 * does. A parent label that is not on the account itself is added as a row
 * anyway: Gmail labels "Sports/Football" and the "Sports" above it are two
 * labels, and the account may have only the one, which would otherwise come
 * loose from where the name says it is.
 */
final readonly class AccountFolderTree
{
    public function __construct(
        private SidebarCounts $sidebar,
        private LabelKinds    $kinds,
    ) {
    }

    /**
     * @param list<Label> $labels the labels bound to this account, in order
     *
     * @return list<AccountFolderNode> the roots
     */
    public function of(Account $account, array $labels): array
    {
        $rows = true === $account->usr?->labelsByAccount
            ? self::withAncestors($labels)
            : array_values(array_filter(
                $labels,
                fn (Label $label): bool => null !== $label->role || true === $this->kinds->isPlaceOn($label, $account),
            ));

        // On plain IMAP the folders' paths decide, and a row with no folder —
        // a tag, listed by account — falls back to its label parent. Without
        // the fallback every tag was a root, whatever its name said.
        // Only for a row with no folder: a top-level folder whose label has a
        // parent label is still top-level, because that is where the server
        // has it.
        $parentOf = true === $account->isPlainImap()
            ? $this->parentsByPath($account, $rows) + $this->parentsOfNonFolders($account, $rows)
            : $this->parentsByLabel($rows);

        $children = [];
        $roots    = [];

        foreach ($rows as $label) {
            $parent = $parentOf[(int) $label->id] ?? null;

            if (null === $parent) {
                $roots[] = $label;
            } else {
                $children[$parent][] = $label;
            }
        }

        return array_map(fn (Label $root): AccountFolderNode => $this->node($root, 0, $children), $roots);
    }

    /**
     * The labels, then the visible parents of any of them that are not among
     * them, each once, in the order they were first reached.
     *
     * @param list<Label> $labels
     *
     * @return list<Label>
     */
    private static function withAncestors(array $labels): array
    {
        $rows = [];

        foreach ($labels as $label) {
            $rows[(int) $label->id] = $label;
        }

        foreach ($labels as $label) {
            for ($up = $label->parent; null !== $up; $up = $up->parent) {
                if (isset($rows[(int) $up->id]) || false === $up->isVisible) {
                    continue;
                }

                $rows[(int) $up->id] = $up;
            }
        }

        return array_values($rows);
    }

    /**
     * @param array<int, list<Label>> $children
     */
    private function node(Label $label, int $depth, array $children): AccountFolderNode
    {
        return new AccountFolderNode(
            $label,
            $depth,
            array_map(fn (Label $child): AccountFolderNode => $this->node($child, $depth + 1, $children), $children[(int) $label->id] ?? []),
        );
    }

    /**
     * @param list<Label> $rows
     *
     * @return array<int, int> label id => parent label id
     */
    private function parentsByPath(Account $account, array $rows): array
    {
        /** @var array<string, int> $byPath folder path => label id */
        $byPath = [];
        /** @var array<int, array{string, string}> $paths label id => [path, delimiter] */
        $paths = [];

        foreach ($rows as $label) {
            $folder = $this->sidebar->folderOf($label, $account);

            if (null === $folder || null === $folder->fullPath || '' === $folder->fullPath) {
                continue;
            }

            $path = self::normalized($folder->fullPath, (string) $folder->delimiter);

            $byPath[$path]           = (int) $label->id;
            $paths[(int) $label->id] = [$path, (string) $folder->delimiter];
        }

        $parents = [];

        foreach ($paths as $id => [$path, $delimiter]) {
            if ('' === $delimiter) {
                continue;
            }

            $segments = explode($delimiter, $path);

            while (count($segments) > 1) {
                array_pop($segments);

                $parent = $byPath[implode($delimiter, $segments)] ?? null;

                if (null !== $parent && $parent !== $id) {
                    $parents[$id] = $parent;

                    break;
                }
            }
        }

        return $parents;
    }

    /**
     * parentsByLabel(), for the rows that have no folder on this account.
     *
     * @param list<Label> $rows
     *
     * @return array<int, int> label id => parent label id
     */
    private function parentsOfNonFolders(Account $account, array $rows): array
    {
        $parents = $this->parentsByLabel($rows);

        foreach ($rows as $label) {
            if (null !== $this->sidebar->folderOf($label, $account)) {
                unset($parents[(int) $label->id]);
            }
        }

        return $parents;
    }

    /**
     * INBOX is case-insensitive in IMAP (RFC 3501 §5.1), so `Inbox/Work` is
     * under `INBOX`.
     */
    private static function normalized(string $path, string $delimiter): string
    {
        if (0 === strcasecmp($path, 'INBOX')) {
            return 'INBOX';
        }

        if ('' !== $delimiter && 0 === strncasecmp($path, 'INBOX' . $delimiter, 5 + strlen($delimiter))) {
            return 'INBOX' . substr($path, 5);
        }

        return $path;
    }

    /**
     * @param list<Label> $rows
     *
     * @return array<int, int> label id => parent label id
     */
    private function parentsByLabel(array $rows): array
    {
        $present = [];

        foreach ($rows as $label) {
            $present[(int) $label->id] = true;
        }

        $parents = [];

        foreach ($rows as $label) {
            for ($up = $label->parent; null !== $up; $up = $up->parent) {
                if (isset($present[(int) $up->id])) {
                    $parents[(int) $label->id] = (int) $up->id;

                    break;
                }
            }
        }

        return $parents;
    }
}
