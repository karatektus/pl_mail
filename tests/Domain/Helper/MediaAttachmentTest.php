<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\MediaAttachment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a picture or a video worth opening a viewer for (#295).
 *
 * The same shape as PdfAttachmentTest, for the same reason: the cases worth
 * writing down are careless senders, and the name must never outvote a type
 * that says otherwise.
 */
final class MediaAttachmentTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function attachments(): iterable
    {
        yield 'a jpeg'                       => ['image/jpeg', 'holiday.jpg', MediaAttachment::IMAGE];
        yield 'with a name parameter'        => ['image/png; name="x.png"', 'x.png', MediaAttachment::IMAGE];
        yield 'uppercase'                    => ['IMAGE/WEBP', null, MediaAttachment::IMAGE];
        yield 'an mp4'                       => ['video/mp4', 'clip.mp4', MediaAttachment::VIDEO];
        yield 'a quicktime'                  => ['video/quicktime', 'IMG_0001.MOV', MediaAttachment::VIDEO];

        yield 'octet-stream named .jpg'      => ['application/octet-stream', 'photo.JPG', MediaAttachment::IMAGE];
        yield 'octet-stream named .mov'      => ['application/octet-stream', 'clip.mov', MediaAttachment::VIDEO];
        yield 'octet-stream named .txt'      => ['application/octet-stream', 'notes.txt', null];
        yield 'octet-stream, no name'        => ['application/octet-stream', null, null];

        // SVG for the reason InlineDisposition gives; TIFF and HEIC because
        // most browsers cannot draw them, and a broken preview is worse than
        // the download.
        yield 'svg'                          => ['image/svg+xml', 'logo.svg', null];
        yield 'octet-stream named .svg'      => ['application/octet-stream', 'logo.svg', null];
        yield 'tiff'                         => ['image/tiff', 'scan.tif', null];
        yield 'heic'                         => ['image/heic', 'IMG_0001.HEIC', null];
        yield 'a video type nobody plays'    => ['video/x-msvideo', 'clip.avi', null];

        // The name never overrides a type that says otherwise.
        yield 'html named .mp4'              => ['text/html', 'clip.mp4', null];
        yield 'pdf named .png'               => ['application/pdf', 'scan.png', null];
        yield 'nothing at all'               => [null, null, null];
        yield 'no type, .jpg name'           => [null, 'photo.jpg', null];
    }

    #[DataProvider('attachments')]
    public function testItRecognisesOnlyWhatABrowserCanShow(?string $type, ?string $filename, ?string $expected): void
    {
        self::assertSame($expected, MediaAttachment::kindOf($type, $filename));
    }

    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function videos(): iterable
    {
        yield 'declared, with a parameter'   => ['Video/MP4; codecs="avc1"', 'clip.mp4', 'video/mp4'];
        yield 'octet-stream named .m4v'      => ['application/octet-stream', 'clip.m4v', 'video/mp4'];
        yield 'octet-stream named .webm'     => ['application/octet-stream', 'clip.webm', 'video/webm'];
        yield 'a picture is not a video'     => ['image/png', 'x.png', null];
        yield 'neither'                      => ['text/plain', 'clip.mp4', null];
    }

    /** Always a bare type from the fixed list — never the sender's string. */
    #[DataProvider('videos')]
    public function testAVideoIsServedUnderAFixedType(?string $type, ?string $filename, ?string $expected): void
    {
        self::assertSame($expected, MediaAttachment::videoType($type, $filename));
    }
}
