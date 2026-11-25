<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Layout;

use App\Domain\DTO\Layout\LayoutPane;
use App\Domain\DTO\Layout\LayoutSplit;
use App\Domain\DTO\Layout\Workspaces;
use App\Domain\Enum\Layout\PaneKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The workspaces document as the server reads it (#755): what is refused, and
 * the two things that are repaired rather than refused.
 *
 * The browser posts the whole document on every change, so this parser is the
 * one gate between what a page (or anybody with a token) sends and what every
 * later page is drawn from. A layout that names a pane twice would draw one
 * pane's DOM into two cells; one with no panes would draw nothing at all. Both
 * must stop here, not in the template.
 *
 * The repairs are the ones the browser's own edits produce in passing — a split
 * left with one child after a close, a row inside a row after a move — so
 * refusing them would turn an ordinary edit into a 422 and a layout that
 * silently stops saving.
 */
final class WorkspacesTest extends TestCase
{
    public function testAValidDocumentRoundTrips(): void
    {
        $document = self::document([self::workspace('w1', 'Triage', self::row('s1', [self::pane('list'), self::pane('message')], [0.4, 0.6]))]);

        $workspaces = Workspaces::fromArray($document);

        self::assertNotNull($workspaces);
        self::assertSame($document, $workspaces->toArray());
    }

    /** @return iterable<string, array{mixed}> */
    public static function refused(): iterable
    {
        $list = self::pane('list');

        yield 'not an array'              => ['nonsense'];
        yield 'no workspaces'             => [['workspaces' => [], 'active' => 0]];
        yield 'ten workspaces'            => [self::document(array_map(static fn (int $i): array => self::workspace('w' . $i, 'W' . $i, $list), range(1, 10)))];
        yield 'the same id twice'         => [self::document([self::workspace('w1', 'A', $list), self::workspace('w1', 'B', $list)])];
        yield 'an id with a slash'        => [self::document([self::workspace('w/1', 'A', $list)])];
        yield 'a blank name'              => [self::document([self::workspace('w1', '   ', $list)])];
        yield 'a name too long'           => [self::document([self::workspace('w1', str_repeat('x', 41), $list)])];
        yield 'an unknown pane'           => [self::document([self::workspace('w1', 'A', self::pane('browser'))])];
        yield 'a pane twice'              => [self::document([self::workspace('w1', 'A', self::row('s1', [$list, $list], [0.5, 0.5]))])];
        yield 'sizes and children differ' => [self::document([self::workspace('w1', 'A', self::row('s1', [$list, self::pane('message')], [1.0]))])];
        yield 'a zero size'               => [self::document([self::workspace('w1', 'A', self::row('s1', [$list, self::pane('message')], [1.0, 0]))])];
        yield 'a size as a string'        => [self::document([self::workspace('w1', 'A', self::row('s1', [$list, self::pane('message')], ['0.5', 0.5]))])];
        yield 'an unknown direction'      => [self::document([self::workspace('w1', 'A', ['t' => 'split', 'id' => 's1', 'dir' => 'diagonal', 'sizes' => [1, 1], 'children' => [$list, self::pane('message')]])])];
        yield 'a split without an id'     => [self::document([self::workspace('w1', 'A', ['t' => 'split', 'dir' => 'row', 'sizes' => [1, 1], 'children' => [$list, self::pane('message')]])])];
        yield 'an invisible share'        => [self::document([self::workspace('w1', 'A', self::row('s1', [$list, self::pane('message')], [1000, 1]))])];
    }

    #[DataProvider('refused')]
    public function testAnInvalidDocumentIsRefusedWhole(mixed $document): void
    {
        self::assertNull(Workspaces::fromArray($document));
    }

    public function testASplitOfOneIsThatOne(): void
    {
        $workspaces = Workspaces::fromArray(self::document([self::workspace('w1', 'A', self::row('s1', [self::pane('calendar')], [1]))]));

        self::assertNotNull($workspaces);
        self::assertInstanceOf(LayoutPane::class, $workspaces->active()->root);
        self::assertSame(PaneKind::Calendar, $workspaces->active()->root->kind);
    }

    public function testARowInsideARowIsFoldedIntoItWithTheSharesMultipliedThrough(): void
    {
        $inner = self::row('s2', [self::pane('message'), self::pane('calendar')], [0.5, 0.5]);
        $workspaces = Workspaces::fromArray(self::document([self::workspace('w1', 'A', self::row('s1', [self::pane('list'), $inner], [0.4, 0.6]))]));

        self::assertNotNull($workspaces);

        $root = $workspaces->active()->root;

        self::assertInstanceOf(LayoutSplit::class, $root);
        self::assertSame([PaneKind::List, PaneKind::Message, PaneKind::Calendar], $root->kinds());
        self::assertEqualsWithDelta([0.4, 0.3, 0.3], $root->sizes, 0.0001);
    }

    public function testAColumnInsideARowIsKept(): void
    {
        $inner = ['t' => 'split', 'id' => 's2', 'dir' => 'col', 'sizes' => [1, 1], 'children' => [self::pane('message'), self::pane('calendar')]];
        $workspaces = Workspaces::fromArray(self::document([self::workspace('w1', 'A', self::row('s1', [self::pane('list'), $inner], [1, 1]))]));

        self::assertNotNull($workspaces);

        $root = $workspaces->active()->root;

        self::assertInstanceOf(LayoutSplit::class, $root);
        self::assertInstanceOf(LayoutSplit::class, $root->children[1]);
    }

    public function testSharesAreScaledToSumToOne(): void
    {
        $workspaces = Workspaces::fromArray(self::document([self::workspace('w1', 'A', self::row('s1', [self::pane('list'), self::pane('message')], [2, 6]))]));

        self::assertNotNull($workspaces);

        $root = $workspaces->active()->root;

        self::assertInstanceOf(LayoutSplit::class, $root);
        self::assertEqualsWithDelta([0.25, 0.75], $root->sizes, 0.0001);
    }

    public function testAnActiveIndexOutOfRangePointsAtTheFirst(): void
    {
        $document           = self::document([self::workspace('w1', 'A', self::pane('list'))]);
        $document['active'] = 3;

        self::assertSame(0, Workspaces::fromArray($document)?->activeIndex);
    }

    public function testTheKindsAWorkspaceDoesNotShowAreItsAbsentOnes(): void
    {
        $workspaces = Workspaces::fromArray(self::document([self::workspace('w1', 'A', self::row('s1', [self::pane('list'), self::pane('tasks')], [1, 1]))]));

        self::assertNotNull($workspaces);
        self::assertSame([PaneKind::Message, PaneKind::Calendar, PaneKind::Contacts], $workspaces->active()->absentKinds());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $workspaces
     *
     * @return array{workspaces: list<array<string, mixed>>, active: int}
     */
    private static function document(array $workspaces): array
    {
        return ['workspaces' => $workspaces, 'active' => 0];
    }

    /**
     * @param array<string, mixed> $layout
     *
     * @return array<string, mixed>
     */
    private static function workspace(string $id, string $name, array $layout): array
    {
        return ['id' => $id, 'name' => $name, 'layout' => $layout];
    }

    /** @return array<string, mixed> */
    private static function pane(string $kind): array
    {
        return ['t' => 'pane', 'kind' => $kind];
    }

    /**
     * @param list<array<string, mixed>> $children
     * @param list<mixed>                $sizes
     *
     * @return array<string, mixed>
     */
    private static function row(string $id, array $children, array $sizes): array
    {
        return ['t' => 'split', 'id' => $id, 'dir' => 'row', 'sizes' => $sizes, 'children' => $children];
    }
}
