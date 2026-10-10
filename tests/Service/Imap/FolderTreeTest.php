<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\DTO\Mail\FolderTreeRow;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Mailbox;
use App\Service\Imap\FolderTree;
use PHPUnit\Framework\TestCase;

/**
 * A flat list of paths, drawn as the tree those paths describe.
 *
 * IMAP stores no parent, so everything a row needs to draw its lines — how
 * deep it is, whether a sibling follows, which ancestors' lines run on past it
 * — is worked out here, and each is a place to be off by one in a way that
 * only shows as a line pointing at the wrong folder.
 *
 * Plain objects and no container: the tree is arithmetic on paths, and the
 * Mailbox is used only as something that carries one.
 */
final class FolderTreeTest extends TestCase
{
    /**
     * A sort by path alone puts "Projects Old" between "Projects" and
     * "Projects/Alpha", because a space sorts before a slash.
     */
    public function testChildrenFollowTheirParentRatherThanTheAlphabet(): void
    {
        $rows = $this->tree(['Projects/Alpha', 'Projects Old', 'Projects', 'Projects/Beta']);

        self::assertSame(
            ['Projects', 'Projects/Alpha', 'Projects/Beta', 'Projects Old'],
            $this->paths($rows),
        );
        self::assertSame([0, 1, 1, 0], array_map(static fn (FolderTreeRow $row): int => $row->depth, $rows));
    }

    public function testTheInboxLeadsItsLevelWhateverTheAlphabetSays(): void
    {
        $rows = $this->tree(['Archive', 'INBOX', 'Zebra'], inbox: 'INBOX');

        self::assertSame(['INBOX', 'Archive', 'Zebra'], $this->paths($rows));
    }

    /** A server that keeps everything under the inbox, with "." between. */
    public function testFoldersKeptUnderTheInboxHangFromIt(): void
    {
        $rows = $this->tree(['INBOX.Sent', 'INBOX', 'INBOX.Drafts'], inbox: 'INBOX', delimiter: '.');

        self::assertSame(['INBOX', 'INBOX.Drafts', 'INBOX.Sent'], $this->paths($rows));
        self::assertSame([0, 1, 1], array_map(static fn (FolderTreeRow $row): int => $row->depth, $rows));
        self::assertTrue($rows[0]->hasChildren);
    }

    /**
     * The lines themselves. For
     *
     *   Projects
     *   ├ Alpha
     *   │ └ 2026
     *   └ Beta
     *
     * Alpha is not the last child and Beta is, and the one line that runs on
     * past a row is Projects' own vertical past "2026" — because Beta is still
     * to come under it.
     */
    public function testEachRowKnowsWhichLinesPassThroughIt(): void
    {
        $rows = $this->byPath($this->tree(['Projects', 'Projects/Alpha', 'Projects/Alpha/2026', 'Projects/Beta']));

        self::assertFalse($rows['Projects/Alpha']->isLast);
        self::assertTrue($rows['Projects/Beta']->isLast);
        self::assertTrue($rows['Projects/Alpha/2026']->isLast);

        self::assertSame([], $rows['Projects/Alpha']->continue, 'a first-level child has no ancestor line to carry');
        self::assertSame([true], $rows['Projects/Alpha/2026']->continue, 'Beta is still to come, so the line runs on');

        self::assertTrue($rows['Projects']->hasChildren);
        self::assertTrue($rows['Projects/Alpha']->hasChildren);
        self::assertFalse($rows['Projects/Beta']->hasChildren);
    }

    /** The contrast to the test above: under a LAST child nothing runs on. */
    public function testNoLineRunsOnUnderALastChild(): void
    {
        $rows = $this->byPath($this->tree(['Projects', 'Projects/Alpha', 'Projects/Beta', 'Projects/Beta/2026']));

        self::assertSame([false], $rows['Projects/Beta/2026']->continue);
    }

    /**
     * A server may list "Archive/2024" and not "Archive". Indenting it under a
     * parent nobody can see leaves it hanging from a line that starts nowhere.
     */
    public function testAFolderWhoseParentIsNotListedHangsFromTheNearestOneThatIs(): void
    {
        $rows = $this->byPath($this->tree(['Archive/2024', 'Work', 'Work/Clients/Acme']));

        self::assertSame(0, $rows['Archive/2024']->depth, 'no listed ancestor at all: the top');
        self::assertSame(1, $rows['Work/Clients/Acme']->depth, '"Work/Clients" is not listed, so it hangs from "Work"');
    }

    public function testASingleRowIsTheSameRowItWouldBeInTheWholeList(): void
    {
        $mailboxes = $this->mailboxes(['Projects', 'Projects/Alpha', 'Projects/Beta']);

        $row = (new FolderTree())->rowFor($mailboxes[1], $mailboxes);

        self::assertSame(1, $row->depth);
        self::assertFalse($row->isLast);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param list<string> $paths
     *
     * @return list<FolderTreeRow>
     */
    private function tree(array $paths, ?string $inbox = null, string $delimiter = '/'): array
    {
        return (new FolderTree())->rows($this->mailboxes($paths, $inbox, $delimiter));
    }

    /**
     * @param list<string> $paths
     *
     * @return list<Mailbox>
     */
    private function mailboxes(array $paths, ?string $inbox = null, string $delimiter = '/'): array
    {
        return array_map(static function (string $path) use ($inbox, $delimiter): Mailbox {
            $segments = explode($delimiter, $path);

            $mailbox = new Mailbox();
            $mailbox->fullPath   = $path;
            $mailbox->name       = end($segments);
            $mailbox->delimiter  = $delimiter;
            $mailbox->specialUse = $path === $inbox ? MailboxSpecialUse::INBOX : null;

            return $mailbox;
        }, $paths);
    }

    /**
     * @param list<FolderTreeRow> $rows
     *
     * @return list<string>
     */
    private function paths(array $rows): array
    {
        return array_map(static fn (FolderTreeRow $row): string => (string) $row->mailbox->fullPath, $rows);
    }

    /**
     * @param list<FolderTreeRow> $rows
     *
     * @return array<string, FolderTreeRow>
     */
    private function byPath(array $rows): array
    {
        return array_combine($this->paths($rows), $rows);
    }
}
