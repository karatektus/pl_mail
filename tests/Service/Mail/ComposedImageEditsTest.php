<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\InlineImageRewriter;
use App\Service\Mail\MailBodySanitizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A picture resized, cropped or turned in the composer keeps what was done to
 * it through a save (#515).
 *
 * The browser makes the picture's bytes from its original and the recipe on
 * the <img> — `data-pl-crop`, `data-pl-rotate`, `data-pl-size` — every time, so a
 * draft whose body lost the recipe would come back uncropped the next time a
 * size is picked, and the width is what the recipient's client shows.
 */
final class ComposedImageEditsTest extends TestCase
{
    private const string EDITED = '<p><img src="cid:a1@plmail" data-cid="a1@plmail" width="320" '
        . 'style="width:320px;max-width:100%;height:auto" data-pl-crop="0.1,0.2,0.5,0.6" data-pl-rotate="90" data-pl-size="small"></p>';

    public function testTheSanitiserKeepsTheRecipe(): void
    {
        $sanitizer = new MailBodySanitizer(self::createStub(UrlGeneratorInterface::class), new NullLogger());

        $html = $sanitizer->sanitizeComposedBody(self::EDITED);

        self::assertStringContainsString('src="cid:a1@plmail"', $html);
        // The sanitiser writes the @ as &#64;; InlineImageRewriter decodes it.
        self::assertMatchesRegularExpression('/data-cid="a1(@|&#64;)plmail"/', $html);
        self::assertStringContainsString('width="320"', $html);
        self::assertStringContainsString('width:320px', $html);
        self::assertStringContainsString('data-pl-crop="0.1,0.2,0.5,0.6"', $html);
        self::assertStringContainsString('data-pl-rotate="90"', $html);
        self::assertStringContainsString('data-pl-size="small"', $html);
    }

    /** A stranger's mail is not a composed body: the markers mean nothing there. */
    public function testReceivedMailDoesNotKeepTheMarkers(): void
    {
        $sanitizer = new MailBodySanitizer(self::createStub(UrlGeneratorInterface::class), new NullLogger());

        $html = $sanitizer->sanitizeFragment('<img src="https://example.org/a.png" data-pl-crop="0,0,1,1" data-pl-rotate="90" data-pl-size="small" width="40">');

        self::assertStringNotContainsString('data-pl-crop', $html);
        self::assertStringNotContainsString('data-pl-rotate', $html);
        self::assertStringNotContainsString('data-pl-size', $html);
        self::assertStringContainsString('width="40"', $html);
    }

    /** Only the src changes between the editor and the wire. */
    public function testTheCidRewriteLeavesTheRecipeAlone(): void
    {
        $rewriter = new InlineImageRewriter(self::createStub(UrlGeneratorInterface::class));

        $html = (string) $rewriter->toCid(str_replace('cid:a1@plmail', '/mail/attachment/7?v=1731000000', self::EDITED));

        self::assertSame(self::EDITED, $html);
    }
}
