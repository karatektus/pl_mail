<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\NoteFormatExtension;
use PHPUnit\Framework\TestCase;

/**
 * The formatting both notes clients share: only the marks become tags, and
 * everything else a note says stays text.
 */
final class NoteFormatExtensionTest extends TestCase
{
    public function testTheMarksBecomeTagsAndEverythingElseIsEscaped(): void
    {
        self::assertSame(
            '<div class="font-semibold text-base">Shop</div>'
            . '<div><strong>milk</strong> and <em>eggs</em> <u>now</u> &lt;b&gt;x&lt;/b&gt;</div>'
            . '<ul class="list-disc pl-5"><li>one</li><li>two</li></ul>'
            . '<div>2*3*4</div>',
            NoteFormatExtension::format("# Shop\n**milk** and *eggs* __now__ <b>x</b>\n- one\n- two\n2*3*4"),
        );
    }
}
