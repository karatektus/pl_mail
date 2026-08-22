<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Service\Mail\MessageSnippet;
use PHPUnit\Framework\TestCase;

/**
 * The preview line is made of what the reader would see.
 *
 * It used to be `bodyText` verbatim — the sender's own text/plain part, which
 * is not a summary of the mail but whatever they chose to put in the half
 * nobody reads. Two failures of that reached one inbox on one day: most rows
 * previewing as "Email is only available as html" (which really is the text
 * part, written by a bulk sender), and one previewing as
 * `<p style="margin:0 0 16px 0;"> Hallo…` (a sender who put markup there).
 *
 * There is deliberately no list of known-bad sentences here. Preferring the
 * rendered body fixes both without recognising either, which is the only
 * version of this that keeps working for the sender nobody has met yet.
 */
final class MessageSnippetTest extends TestCase
{
    private MessageSnippet $snippet;

    protected function setUp(): void
    {
        $this->snippet = new MessageSnippet();
    }

    public function testTheRenderedBodyWinsOverTheSendersTextPart(): void
    {
        $message               = new Message();
        $message->bodyText     = 'Email is only available as html';
        $message->bodyHtmlSafe = '<p>Ihre Sendung kommt heute an.</p>';

        self::assertSame('Ihre Sendung kommt heute an.', $this->snippet->of($message));
    }

    /** The other half of the same report. */
    public function testMarkupInTheTextPartIsNotShownAsText(): void
    {
        $message               = new Message();
        $message->bodyText     = '<p style="margin:0 0 16px 0;"> Hallo, ich melde mich noch einmal';
        $message->bodyHtmlSafe = null;

        $snippet = $this->snippet->of($message);

        self::assertStringNotContainsString('<p', $snippet);
        self::assertStringNotContainsString('margin', $snippet);
        self::assertStringStartsWith('Hallo, ich melde mich', $snippet);
    }

    /**
     * Blocks become spaces before the tags go.
     *
     * Without it the last word of one paragraph runs into the first of the
     * next — "Hallo PaulWie geht es dir" — which reads as corrupted data rather
     * than as a rendering choice.
     */
    public function testBlocksBecomeSpacesRatherThanRunningTogether(): void
    {
        $message               = new Message();
        $message->bodyHtmlSafe = '<p>Hallo Paul</p><p>Wie geht es dir?</p>';

        self::assertSame('Hallo Paul Wie geht es dir?', $this->snippet->of($message));
    }

    public function testEntitiesAndNonBreakingSpacesAreResolved(): void
    {
        $message               = new Message();
        $message->bodyHtmlSafe = '<p>Rechnung&nbsp;&nbsp;&amp;&nbsp;Mahnung &lt;wichtig&gt;</p>';

        self::assertSame('Rechnung & Mahnung <wichtig>', $this->snippet->of($message));
    }

    /**
     * A plain-text mail still gets a preview. The HTML being preferred must not
     * mean the text part is unreachable when it is the only body there is.
     */
    public function testAPlainTextMailFallsBackToItsTextPart(): void
    {
        $message           = new Message();
        $message->bodyText = "Zeile eins\nZeile zwei";

        self::assertSame('Zeile eins Zeile zwei', $this->snippet->of($message));
    }

    /**
     * An HTML body that sanitises down to nothing — an image-only mail, say —
     * is not a body. Falling through to the text part is what keeps such a mail
     * from previewing as an empty row.
     */
    public function testAnEmptyRenderedBodyFallsThroughRatherThanWinning(): void
    {
        $message               = new Message();
        $message->bodyHtmlSafe = '<div>   </div>';
        $message->bodyText     = 'Der eigentliche Text';

        self::assertSame('Der eigentliche Text', $this->snippet->of($message));
    }

    public function testAMessageWithNoBodyAtAllIsEmptyRatherThanAnError(): void
    {
        self::assertSame('', $this->snippet->of(new Message()));
        self::assertSame('', $this->snippet->of(null));
    }

    /**
     * The sanitised copy, never the raw one.
     *
     * Stripping tags off raw sender HTML would put the CONTENTS of a script or
     * style element into a list row as text — which is both nonsense on screen
     * and the one place a reader would not expect attacker-authored text to
     * turn up verbatim.
     */
    public function testTheRawBodyIsNeverTheSource(): void
    {
        $message               = new Message();
        $message->bodyHtml     = '<script>alert("raw")</script><p>Roh</p>';
        $message->bodyHtmlSafe = '<p>Bereinigt</p>';

        $snippet = $this->snippet->of($message);

        self::assertSame('Bereinigt', $snippet);
        self::assertStringNotContainsString('alert', $snippet);
    }
}
