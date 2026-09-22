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

    /**
     * The preview starts at the message, not at the chrome above it.
     *
     * Bulk mail opens with a view-in-browser line, a logo, a nav bar and a
     * greeting, and the preview used to spend its two hundred characters on
     * them — so a list of newsletters previewed as a column of "View in
     * browser", which is the one thing every row has in common.
     *
     * No sentence here is recognised. The rule is structural: the first block
     * long enough to be prose and containing a space is where the preview
     * begins. That is what makes it hold in a language nobody here has read.
     */
    public function testThePreviewSkipsThePreambleAndStartsAtTheProse(): void
    {
        $message               = new Message();
        $message->bodyHtmlSafe = '<div>View in browser</div>'
            . '<div>Unsubscribe</div>'
            . '<p>Hallo Paul,</p>'
            . '<p>deine Bestellung ist heute unterwegs und kommt voraussichtlich am Freitag an.</p>';

        self::assertSame(
            'deine Bestellung ist heute unterwegs und kommt voraussichtlich am Freitag an.',
            $this->snippet->of($message),
        );
    }

    /** German, English or otherwise: the test is the shape, not the words. */
    public function testTheSameHoldsForAPreambleInAnyLanguage(): void
    {
        $message               = new Message();
        $message->bodyHtmlSafe = '<div>Im Browser ansehen</div><div>Abmelden</div>'
            . '<p>Your parcel is on its way and should reach you on Friday afternoon.</p>';

        self::assertSame(
            'Your parcel is on its way and should reach you on Friday afternoon.',
            $this->snippet->of($message),
        );
    }

    /**
     * A short mail is not a mail with a preamble, and must not be emptied out.
     *
     * Nothing in it clears the prose bar, which is exactly the case the
     * fallback exists for: the whole text is the preview, as it always was.
     */
    public function testAShortMessageIsShownWhole(): void
    {
        $message               = new Message();
        $message->bodyHtmlSafe = '<p>Sounds good, see you then.</p>';

        self::assertSame('Sounds good, see you then.', $this->snippet->of($message));
    }

    /**
     * A long URL on its own line is not prose, however long it is.
     *
     * The space is what decides it. Without that test a tracking link would
     * pass the length bar and become the preview of every newsletter that
     * opens with one — which is the failure this whole rule exists to avoid,
     * arrived at from the other direction.
     */
    public function testAnUnbrokenLinkIsNotMistakenForASentence(): void
    {
        $message               = new Message();
        $message->bodyHtmlSafe = '<div>https://example.test/campaign/9f2a1c/click?recipient=paul&amp;id=44</div>'
            . '<p>Die Rechnung fuer September liegt dieser Nachricht als PDF bei.</p>';

        self::assertSame(
            'Die Rechnung fuer September liegt dieser Nachricht als PDF bei.',
            $this->snippet->of($message),
        );
    }

    /** Once the prose starts, everything after it belongs to the preview. */
    public function testShortBlocksAfterTheProseAreKept(): void
    {
        $message               = new Message();
        $message->bodyHtmlSafe = '<div>View in browser</div>'
            . '<p>Wir haben deine Anfrage erhalten und melden uns in den naechsten Tagen.</p>'
            . '<p>Viele Gruesse</p><p>Lea</p>';

        self::assertSame(
            'Wir haben deine Anfrage erhalten und melden uns in den naechsten Tagen. Viele Gruesse Lea',
            $this->snippet->of($message),
        );
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
