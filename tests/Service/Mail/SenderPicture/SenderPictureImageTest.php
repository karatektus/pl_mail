<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\SenderPicture;

use App\Domain\DTO\Mail\FetchedPicture;
use App\Domain\Helper\SvgSanitizer;
use App\Service\Mail\SenderPicture\SenderPictureImage;
use App\Tests\Support\Mail\SenderPictureFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Nothing a stranger's server sent is stored as it came (#639).
 *
 * A raster is decoded and drawn again, so what is kept is provably a picture
 * and nothing else; an SVG loses everything but its drawing. These are the
 * claims, because a version that stored the bytes it fetched would pass every
 * test that only looked at whether "a picture" came out.
 */
final class SenderPictureImageTest extends TestCase
{
    private SenderPictureImage $image;

    protected function setUp(): void
    {
        $this->image = new SenderPictureImage(new SvgSanitizer());
    }

    public function testARasterIsRedrawnAsASmallSquarePngRatherThanKept(): void
    {
        $original = SenderPictureFixtures::png(400, 200) . 'trailing-payload';
        $stored   = $this->image->normalize(new FetchedPicture($original, 'image/png', 'test'));

        self::assertNotNull($stored);
        self::assertSame('png', $stored['extension']);
        self::assertStringNotContainsString('trailing-payload', $stored['bytes']);

        $size = getimagesizefromstring($stored['bytes']);
        self::assertSame([SenderPictureImage::EDGE, SenderPictureImage::EDGE], [$size[0] ?? null, $size[1] ?? null]);
    }

    public function testAFaviconSizedPictureIsNotWorthKeeping(): void
    {
        self::assertNull($this->image->normalize(new FetchedPicture(SenderPictureFixtures::png(16, 16), 'image/png', 'test')));
    }

    public function testBytesThatAreNotAPictureAreRefusedWhateverTheyClaim(): void
    {
        self::assertNull($this->image->normalize(new FetchedPicture('<html>nope</html>', 'image/png', 'test')));
    }

    public function testAnSvgKeepsItsDrawingAndLosesItsScript(): void
    {
        $svg    = SenderPictureFixtures::svg('<script>alert(1)</script>');
        $stored = $this->image->normalize(new FetchedPicture($svg, 'text/plain', 'test'));

        self::assertNotNull($stored);
        self::assertSame('svg', $stored['extension']);
        self::assertStringContainsString('<rect', $stored['bytes']);
        self::assertStringNotContainsString('script', $stored['bytes']);
    }
}
