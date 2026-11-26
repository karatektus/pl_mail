<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailBodySanitizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A mail's inline styles cannot take its content out of the message.
 *
 * Inline styles are kept on purpose — in mail they are the design — and the
 * reading pane can afford that because it is a sandboxed frame. The reply
 * composer and the print page are not: there the sender's HTML sits in the
 * app's own document, and `position: fixed` on a full-size element drew the
 * sender's page over plMail when the reader pressed Reply (issue #34).
 */
final class EscapingStylesInMailTest extends TestCase
{
    private MailBodySanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new MailBodySanitizer(self::createStub(UrlGeneratorInterface::class), new NullLogger());
    }

    public function testAFixedElementLosesItsPositionAndKeepsItsOtherStyles(): void
    {
        $html = $this->sanitizer->sanitizeFragment(
            '<div style="position:fixed; top:0; left:0; color: red">Sign in again</div>',
        );

        self::assertStringNotContainsString('position', $html);
        self::assertStringContainsString('color: red', $html);
        self::assertStringContainsString('Sign in again', $html);
    }

    /** The spellings a pattern match on "position:fixed" would wave through. */
    public function testTheDeclarationIsRecognisedHoweverItIsWritten(): void
    {
        foreach (['POSITION : Fixed', 'position:/**/fixed', "position:\tsticky", 'position: fixed !important'] as $declaration) {
            $html = $this->sanitizer->sanitizeFragment(sprintf('<p style="margin: 0; %s">x</p>', $declaration));

            self::assertStringNotContainsStringIgnoringCase('fixed', $html, $declaration);
            self::assertStringNotContainsStringIgnoringCase('sticky', $html, $declaration);
            self::assertStringContainsString('margin: 0', $html, $declaration);
        }
    }

    /**
     * An absolutely positioned box leaves the flow, so the text after it runs
     * underneath. Scriptura-generated mail puts its header banner in one (#626).
     */
    public function testAnAbsoluteBannerDoesNotCoverTheTextBelowIt(): void
    {
        $body = '<table style="position: absolute; width: 100%"><tr><td>'
            . '<div style="position: absolute; width: 595pt"><img src="https://example.com/b.jpg" style="display: block"></div>'
            . '</td></tr></table><p>Beste,</p>';

        $html = $this->sanitizer->sanitizeFragment($body);

        self::assertStringNotContainsString('absolute', $html);
        self::assertStringContainsString('width: 595pt', $html);
        self::assertStringContainsString('display: block', $html);
        self::assertStringContainsString('Beste,', $html);
    }

    /** `z-index` only matters where the message shares the app's document. */
    public function testZIndexIsDroppedOnlyWhereTheMessageSharesTheAppDocument(): void
    {
        $body = '<div style="position:absolute; z-index: 99999; inset: 0">x</div>';

        self::assertStringContainsString('z-index', $this->sanitizer->sanitizeFragment($body));

        $composed = $this->sanitizer->sanitizeComposedBody($body);

        self::assertStringNotContainsString('absolute', $composed);
        self::assertStringNotContainsString('z-index', $composed);
        self::assertStringContainsString('inset: 0', $composed);
    }

    public function testOrdinaryStylesAreUntouched(): void
    {
        $html = $this->sanitizer->sanitizeFragment('<div style="padding: 12px; background-position: center; position: relative">x</div>');

        self::assertStringContainsString('padding: 12px', $html);
        self::assertStringContainsString('background-position: center', $html);
        self::assertStringContainsString('position: relative', $html);
    }
}
