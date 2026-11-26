<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailBodySanitizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A composed body's paragraphs carry `margin: 0`, as the composer drew them.
 *
 * The composer makes a paragraph of every line and shows it with no margin;
 * a mail program draws a bare `<p>` with a line of space above and below. So
 * a sent mail had a blank line after every line, in the recipient's client and
 * in plMail's own reading pane, while it looked right as it was written. The
 * editor's clean-up now writes the margin itself; this is the server's copy of
 * that rule, for a page loaded before it and for any other client.
 */
final class ComposedParagraphGapsTest extends TestCase
{
    private MailBodySanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new MailBodySanitizer(self::createStub(UrlGeneratorInterface::class), new NullLogger());
    }

    public function testEachLineTheWriterWroteHasNoMargin(): void
    {
        $out = $this->sanitizer->sanitizeComposedBody('<p>Hello Pavel,</p><p><br></p><p>The panoramas.</p>');

        self::assertSame(3, substr_count($out, '<p style="margin: 0">'), $out);
    }

    /** A style the paragraph already had stays, with the margin after it. */
    public function testAParagraphKeepsItsOwnStyle(): void
    {
        $out = $this->sanitizer->sanitizeComposedBody('<p style="color: #c00;">Red.</p>');

        self::assertStringContainsString('color: #c00; margin: 0', $out);
    }

    /** A margin of its own — pasted, and shown with it in the editor — is the paragraph's. */
    public function testAParagraphWithAMarginOfItsOwnIsLeftAlone(): void
    {
        $out = $this->sanitizer->sanitizeComposedBody('<p style="margin-bottom: 12px">Spaced.</p><p style="margin:0 0 1em">Too.</p>');

        self::assertStringContainsString('<p style="margin-bottom: 12px">', $out);
        self::assertStringContainsString('<p style="margin:0 0 1em">', $out);
        self::assertStringNotContainsString('margin: 0"', $out);
    }

    /** The quote under a reply or a forward is the sender's mail as the sender made it. */
    public function testTheQuoteIsLeftAsTheSenderMadeIt(): void
    {
        $out = $this->sanitizer->sanitizeComposedBody(
            '<p>Passt!</p>'
            . '<div data-quoted="1"><div>On Mon, Sven wrote:</div>'
            . '<blockquote><p>The original.</p></blockquote>'
            . '<p>Between.</p></div>'
            . '<blockquote><p>A quote from a draft older than data-quoted.</p></blockquote>',
        );

        self::assertStringContainsString('<p style="margin: 0">Passt!</p>', $out);
        self::assertStringContainsString('<p>The original.</p>', $out);
        self::assertStringContainsString('<p>Between.</p>', $out);
        self::assertStringContainsString('<p>A quote from a draft older than data-quoted.</p>', $out);
    }

    /** Saved and reopened, a draft is saved again: the second pass changes nothing. */
    public function testASecondPassChangesNothing(): void
    {
        $once = $this->sanitizer->sanitizeComposedBody('<p>One</p><p style="color:red">Two</p><div>Three</div>');

        self::assertSame($once, $this->sanitizer->sanitizeComposedBody($once));
    }

    /** What the editor's clean-up already wrote, `margin: 0px`, is a margin of its own. */
    public function testTheEditorsOwnMarginIsNotAddedTwice(): void
    {
        $out = $this->sanitizer->sanitizeComposedBody('<p style="margin: 0px;">From the editor.</p>');

        self::assertStringContainsString('<p style="margin: 0px">', $out);
        self::assertSame(1, substr_count($out, 'margin'), $out);
    }
}
