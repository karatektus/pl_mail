<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\AttachmentStorageHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A part its sender did not name is named for its type, so it carries the
 * extension the machine it is downloaded to opens it by (#718). webklex named
 * these by a checksum of their bytes, which carried none.
 */
final class AttachmentFallbackNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function types(): iterable
    {
        yield 'a PDF'                     => ['application/pdf', 'attachment.pdf'];
        yield 'an attached message'       => ['message/rfc822', 'attachment.eml'];
        yield 'an invite'                 => ['text/calendar', 'attachment.ics'];
        yield 'written in capitals'       => ['IMAGE/PNG', 'attachment.png'];
        // Nothing to go on: the bare word rather than a guess.
        yield 'a type nobody has a name for' => ['application/x-plmail-unknown', 'attachment'];
        yield 'no type at all'            => ['', 'attachment'];
    }

    #[DataProvider('types')]
    public function testANamelessPartIsNamedForItsType(string $contentType, string $expected): void
    {
        self::assertSame($expected, AttachmentStorageHelper::fallbackName($contentType));
    }
}
