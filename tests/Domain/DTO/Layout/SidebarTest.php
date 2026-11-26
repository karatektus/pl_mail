<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Layout;

use App\Domain\DTO\Layout\Sidebar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A sidebar of the user's own as the server reads it (#783).
 *
 * The page posts the user's sidebars whole, so this is the gate. A sidebar
 * that is not one is refused; a single row the server does not understand is
 * dropped instead, so one odd row does not cost somebody the rest.
 */
final class SidebarTest extends TestCase
{
    public function testEveryKindOfRowComesThrough(): void
    {
        $entries = [
            ['t' => 'head', 'text' => 'Plan'],
            ['t' => 'sys', 'row' => 'starred'],
            ['t' => 'acc', 'acc' => 12],
            ['t' => 'inbox', 'inbox' => 3],
            ['t' => 'label', 'label' => 40],
            ['t' => 'cal', 'cal' => 7],
            ['t' => 'tasks', 'list' => 5],
            ['t' => 'clock'],
            ['t' => 'block', 'b' => 'calendars'],
        ];

        $sidebar = Sidebar::fromArray(['id' => 'sb1', 'name' => ' Work ', 'entries' => $entries]);

        self::assertNotNull($sidebar);
        self::assertSame(['id' => 'sb1', 'name' => 'Work', 'entries' => $entries], $sidebar->toArray());
    }

    public function testARowNotUnderstoodIsDroppedAndTheRestKept(): void
    {
        $sidebar = Sidebar::fromArray(['id' => 'sb1', 'name' => 'Work', 'entries' => [
            ['t' => 'sys', 'row' => 'nonsense'],
            ['t' => 'acc', 'acc' => '12'],
            ['t' => 'head', 'text' => '   '],
            ['t' => 'block', 'b' => 'everything'],
            ['t' => 'browser'],
            'a string',
            ['t' => 'head', 'text' => '  Kept  ', 'extra' => 'dropped'],
        ]]);

        self::assertSame([['t' => 'head', 'text' => 'Kept']], $sidebar?->entries);
    }

    /** @return iterable<string, array{mixed}> */
    public static function refused(): iterable
    {
        yield 'not an array'          => ['sidebar'];
        yield 'no id'                 => [['name' => 'Work', 'entries' => []]];
        yield 'an id with a slash'    => [['id' => 'a/b', 'name' => 'Work', 'entries' => []]];
        yield 'the Mail sidebar\'s id' => [['id' => 'mail', 'name' => 'Mail', 'entries' => []]];
        yield 'a blank name'          => [['id' => 'sb1', 'name' => '  ', 'entries' => []]];
        yield 'a name too long'       => [['id' => 'sb1', 'name' => str_repeat('x', 41), 'entries' => []]];
        yield 'entries not a list'    => [['id' => 'sb1', 'name' => 'Work', 'entries' => ['a' => ['t' => 'clock']]]];
        yield 'too many entries'      => [['id' => 'sb1', 'name' => 'Work', 'entries' => array_fill(0, 61, ['t' => 'clock'])]];
    }

    #[DataProvider('refused')]
    public function testWhatIsNotASidebarIsRefused(mixed $data): void
    {
        self::assertNull(Sidebar::fromArray($data));
    }
}
