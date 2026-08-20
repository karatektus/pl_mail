<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\ImageProxySigner;
use App\Service\Mail\RemoteContentBlocker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The bug this exists to prevent: a message body reaching the browser with a
 * live reference to the sender's server on it, which is a read receipt the
 * reader did not agree to send.
 *
 * Every case below is a shape a tracking pixel has actually worn.
 */
final class RemoteContentBlockerTest extends TestCase
{
    private RemoteContentBlocker $blocker;

    protected function setUp(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string =>
                '/mail/image-proxy?u=' . rawurlencode((string) $parameters['u']) . '&s=' . $parameters['s'],
        );

        $this->blocker = new RemoteContentBlocker(
            new ImageProxySigner($urls, 'test-secret'),
            new NullLogger(),
        );
    }

    public function testRemoteImageLosesItsSourceAndIsCounted(): void
    {
        $result = $this->blocker->rewrite(
            '<p><img src="https://tracker.example/pixel.gif" width="1" height="1"></p>',
            false,
        );

        self::assertSame(1, $result->blocked);
        self::assertStringNotContainsString('tracker.example/pixel.gif', $result->html);
        self::assertStringContainsString('src="data:image/', $result->html);
        self::assertStringContainsString('data-plmail-blocked="1"', $result->html);
    }

    /**
     * The placeholder is shaped like the image it stands in for, and this is
     * the case that made it necessary.
     *
     * The reading frame styles images `height: auto`, so the rendered height is
     * computed from the placeholder's own intrinsic ratio — CSS beats the
     * `height` attribute, which is only a presentational hint. With a 1×1 GIF
     * standing in for everything, that ratio was always 1:1, and a banner
     * declared six hundred wide and eighty tall was drawn six hundred tall. A
     * square. On a newsletter that is several screens of hatching.
     *
     * An SVG with a viewBox and no dimensions has a ratio and no size, which is
     * exactly and only what is wanted here.
     */
    public function testTheBlockedBoxKeepsTheShapeTheSenderDeclared(): void
    {
        $result = $this->blocker->rewrite(
            '<img src="https://cdn.example/banner.png" width="600" height="80">',
            false,
        );

        self::assertStringContainsString('viewBox="0 0 600 80"', self::placeholderIn($result->html));
        self::assertStringContainsString('data-plmail-box="1"', $result->html);
    }

    /** An inline style is the more specific statement and is taken over the attribute. */
    public function testAnInlineStyleOutranksTheAttributeForTheShape(): void
    {
        $result = $this->blocker->rewrite(
            '<img src="https://cdn.example/b.png" width="600" height="80" style="width:300px;height:100px">',
            false,
        );

        self::assertStringContainsString('viewBox="0 0 300 100"', self::placeholderIn($result->html));
    }

    /**
     * Half a shape is not a shape.
     *
     * Nothing here can know what a 600px-wide image is supposed to be tall, and
     * inventing a number would put a made-up box on somebody's screen. It gets
     * the blank placeholder and no `data-plmail-box`, which is what the
     * stylesheet's "never taller than this" backstop keys off.
     */
    public function testAnImageWithOnlyAWidthIsNotShaped(): void
    {
        $result = $this->blocker->rewrite(
            '<img src="https://cdn.example/b.png" width="600">',
            false,
        );

        self::assertStringContainsString('data:image/gif;base64,', $result->html);
        self::assertStringNotContainsString('data-plmail-box', $result->html);
    }

    /**
     * A ratio needs two numbers in the same unit. "Half the width, eighty
     * pixels tall" is two facts about different things.
     */
    public function testPercentagesDoNotMakeAShape(): void
    {
        $result = $this->blocker->rewrite(
            '<img src="https://cdn.example/b.png" width="50%" height="80">',
            false,
        );

        self::assertStringContainsString('data:image/gif;base64,', $result->html);
        self::assertStringNotContainsString('data-plmail-box', $result->html);
    }

    /** The numbers reach a viewBox, so their magnitude is not the sender's to choose. */
    public function testAnAbsurdDimensionIsRefusedRatherThanEmbedded(): void
    {
        $result = $this->blocker->rewrite(
            '<img src="https://cdn.example/b.png" width="99999999" height="80">',
            false,
        );

        self::assertStringNotContainsString('data-plmail-box', $result->html);
    }

    /** The decoded placeholder, for asserting on what is actually in it. */
    private static function placeholderIn(string $html): string
    {
        self::assertSame(
            1,
            preg_match('/src="data:image\/svg\+xml;base64,([^"]+)"/', $html, $found),
            'no shaped placeholder in: '.$html,
        );

        return base64_decode($found[1], true) ?: '';
    }

    /**
     * The stashed URL is the PROXY's, never the sender's. It matters because
     * the un-block happens in the browser: if the sender's URL were parked
     * there, one client-side mistake would put it straight back into a live
     * src, and the whole proxy would be optional.
     */
    public function testTheStashedUrlPointsAtTheProxyNotTheSender(): void
    {
        $result = $this->blocker->rewrite('<img src="https://tracker.example/p.gif">', false);

        self::assertMatchesRegularExpression(
            '/data-plmail-src="\/mail\/image-proxy\?u=[^"]+&amp;s=[0-9a-f]{32}"/',
            $result->html,
        );
    }

    public function testProtocolRelativeSourcesAreRemoteToo(): void
    {
        // The sanitizer allows relative medias, so these survive it — and they
        // are exactly as remote as anything carrying a scheme.
        $result = $this->blocker->rewrite('<img src="//tracker.example/p.gif">', false);

        self::assertSame(1, $result->blocked);
        self::assertStringNotContainsString('//tracker.example/p.gif', $result->html);
    }

    /**
     * cid: images were resolved to our own attachment route at ingest. They are
     * the message's own bytes, already on our disk, and blocking them would
     * break every mail with an inline logo for no privacy gain whatsoever.
     */
    public function testInlineAttachmentImagesAreUntouched(): void
    {
        $html   = '<img src="/mail/attachment/42" alt="logo">';
        $result = $this->blocker->rewrite($html, false);

        self::assertSame(0, $result->blocked);
        self::assertStringContainsString('src="/mail/attachment/42"', $result->html);
    }

    public function testDataUriImagesAreUntouched(): void
    {
        $html   = '<img src="data:image/png;base64,iVBORw0KGgo=">';
        $result = $this->blocker->rewrite($html, false);

        self::assertSame(0, $result->blocked);
        self::assertStringContainsString('data:image/png;base64,iVBORw0KGgo=', $result->html);
    }

    /**
     * A background image is a tracking pixel that happens to be styled, and
     * MailBodySanitizer's CSS inlining lands every <style> rule on a style
     * attribute — so this is where they all end up.
     */
    public function testRemoteCssBackgroundsAreBlocked(): void
    {
        // A full table, because the HTML parser drops a <td> that is not in
        // one — and a <td> is where an email actually puts a background.
        $result = $this->blocker->rewrite(
            '<table><tr><td style="background: url(https://tracker.example/bg.png) no-repeat; color: red">x</td></tr></table>',
            false,
        );

        self::assertSame(1, $result->blocked);
        self::assertStringNotContainsString('tracker.example/bg.png', $result->html);
        // The rest of the declaration survives — only the reference goes.
        self::assertStringContainsString('color: red', $result->html);
        self::assertStringContainsString('none no-repeat', $result->html);
    }

    public function testOptingInRoutesEverythingThroughTheProxy(): void
    {
        $result = $this->blocker->rewrite(
            '<img src="https://tracker.example/pixel.gif">'
            . '<table><tr><td style="background: url(https://tracker.example/bg.png)">x</td></tr></table>',
            true,
        );

        self::assertSame(0, $result->blocked);
        self::assertSame(2, $result->allowed);
        // Opting in is opting in to the PROXY, not to the sender.
        self::assertStringNotContainsString('src="https://tracker.example', $result->html);
        self::assertStringContainsString('/mail/image-proxy?u=', $result->html);
    }

    public function testAnEmptyBodyIsNotARemoteBody(): void
    {
        $result = $this->blocker->rewrite(null, false);

        self::assertSame('', $result->html);
        self::assertSame(0, $result->blocked);
        self::assertSame(0, $result->allowed);
    }

    /**
     * Fail closed. A body the parser cannot make sense of is a body whose
     * remote references cannot be found — so it is served as text, not served
     * as-is. Rendering it unchanged would load every pixel in it.
     */
    public function testUnparseableBodiesLoseTheirMarkupRatherThanTheirBlocking(): void
    {
        // Valid UTF-8 but structurally hostile; whatever the parser does with
        // it, the result must not contain a live remote reference.
        $result = $this->blocker->rewrite(
            str_repeat('<div>', 5) . '<img src="https://tracker.example/p.gif">',
            false,
        );

        self::assertStringNotContainsString('src="https://tracker.example/p.gif"', $result->html);
    }

    public function testTextIsPreservedThroughBlocking(): void
    {
        $result = $this->blocker->rewrite('<p>Hello — über café</p>', false);

        self::assertStringContainsString('über café', $result->html);
    }
}
