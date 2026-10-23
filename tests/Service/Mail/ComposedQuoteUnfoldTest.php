<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailBodySanitizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A composed body loses the composer's "show quoted text" fold (#257).
 *
 * The compose window hides the quote under a reply with an inline
 * `display: none`, inside a `[data-quote-wrapped]` beside a
 * `[data-quote-toggle]` button. Its clean-up removed the button and nothing
 * else, so a reply sent with the quote still folded — how the composer opens —
 * reached its recipient with the history invisible. The editor no longer
 * sends the fold; this is the server's copy of that rule, which holds for a
 * page loaded before the fix and for any other client.
 */
final class ComposedQuoteUnfoldTest extends TestCase
{
    private MailBodySanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new MailBodySanitizer(self::createStub(UrlGeneratorInterface::class), new NullLogger());
    }

    public function testAFoldedReplyIsSentUnfolded(): void
    {
        $out = $this->sanitizer->sanitizeComposedBody(
            '<p>Passt!</p>'
            . '<div data-quote-wrapped="1" style="margin-top: 0.5em;">'
            . '<button type="button" data-quote-toggle="1">··· (show quoted text)</button>'
            . '<div data-quoted="1" style="display: none;">'
            . '<div style="font-size:0.85em;color:#555">On Mon, Oct 19, 2026, Sven wrote:</div>'
            . '<blockquote><p>The original.</p></blockquote>'
            . '</div></div>',
        );

        self::assertStringContainsString('The original.', $out);
        self::assertStringNotContainsString('display', $out);
        self::assertStringNotContainsString('data-quote-wrapped', $out);
        self::assertStringNotContainsString('data-quote-toggle', $out);
        self::assertStringNotContainsString('show quoted text', $out);
        self::assertStringContainsString('data-quoted="1"', $out, 'the quote marker stays — it is how the composer folds a reopened draft');
        self::assertStringContainsString('font-size:0.85em', $out, 'the quote keeps its own styling');
    }

    /** Only the fold goes: a style the quote's own content carries stays. */
    public function testOtherStylesOnTheQuoteSurvive(): void
    {
        $out = $this->sanitizer->sanitizeComposedBody(
            '<p>FYI.</p>'
            . '<div data-quote-wrapped="1"><div data-quoted="1" style="border-top:1px solid #e0e0e0;display:none">'
            . '<p>---------- Forwarded message ----------</p><div>The original.</div></div></div>',
        );

        self::assertStringContainsString('border-top:1px solid #e0e0e0', $out);
        self::assertStringNotContainsString('display', $out);
    }

    /** A body that was never folded is left as it was. */
    public function testABodyWithoutAFoldIsUntouched(): void
    {
        $body = '<p>Hallo</p><div style="display:none">preheader</div>';

        self::assertSame(
            $this->sanitizer->sanitizeComposedBody($body),
            $this->sanitizer->sanitizeComposedBody($this->sanitizer->sanitizeComposedBody($body)),
        );
        self::assertStringContainsString('display:none', $this->sanitizer->sanitizeComposedBody($body));
    }
}
