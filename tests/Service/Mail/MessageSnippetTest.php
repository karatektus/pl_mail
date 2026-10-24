<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Message;
use App\Service\Mail\MessageSnippet;
use App\Service\Mail\QuoteCollapser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        $this->snippet = new MessageSnippet(new QuoteCollapser($this->createStub(TranslatorInterface::class)));
    }

    /**
     * The preview of bulk mail starts at the message, not at the chrome above
     * it.
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
        $message->category     = MessageCategory::Updates;
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
        $message->category     = MessageCategory::Promotions;
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
        $message->category     = MessageCategory::Updates;
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
        $message->category     = MessageCategory::Updates;
        $message->bodyHtmlSafe = '<div>View in browser</div>'
            . '<p>Wir haben deine Anfrage erhalten und melden uns in den naechsten Tagen.</p>'
            . '<p>Viele Gruesse</p><p>Lea</p>';

        self::assertSame(
            'Wir haben deine Anfrage erhalten und melden uns in den naechsten Tagen. Viele Gruesse Lea',
            $this->snippet->of($message),
        );
    }

    /**
     * The reported failure (#257): a one-line reply previewed as the quote.
     *
     * "Danke, passt!" is too short to count as prose, so the preview skipped
     * it and started at the attribution line, the first long block it found.
     * With the history cut off first there is nothing to skip into, and a
     * reply to the reader's own mail no longer previews as their own words.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function htmlReplies(): iterable
    {
        yield 'gmail' => [
            '<div dir="ltr">Danke, passt!</div>'
            . '<div><div dir="ltr">On Mon, Oct 19, 2026 at 3:25 PM Sven &lt;sven@example.test&gt; wrote:</div>'
            . '<blockquote><div>Hallo Paul, hier ist der lange Text meiner eigenen Mail an dich.</div></blockquote></div>',
            'Danke, passt!',
        ];

        yield 'german attribution' => [
            '<p>Passt.</p><div>Am 19.10.2026 um 15:25 schrieb Sven &lt;sven@example.test&gt;:</div>'
            . '<blockquote><p>Hallo Paul, hier ist der lange Text meiner eigenen Mail an dich.</p></blockquote>',
            'Passt.',
        ];

        yield 'outlook header block' => [
            '<div><p>Ja, anbei.</p>'
            . '<div><p><b>Von:</b> Sven &lt;sven@example.test&gt;<br><b>Gesendet:</b> Montag, 19. Oktober 2026 15:25<br>'
            . '<b>An:</b> Paul<br><b>Betreff:</b> Budget</p></div>'
            . '<p>Hallo Paul, wo liegt eigentlich die Datei mit dem Budget für das nächste Jahr?</p></div>',
            'Ja, anbei.',
        ];
    }

    #[DataProvider('htmlReplies')]
    public function testAReplyPreviewsWhatTheSenderWroteNotTheQuote(string $html, string $expected): void
    {
        $message               = new Message();
        $message->bodyHtmlSafe = $html;

        self::assertSame($expected, $this->snippet->of($message));
    }

    /**
     * Plain-text replies quote with `>`, and the reading view's collapser
     * never sees them. The line that introduced the quote goes with it when
     * it ends in a colon — in any language, without knowing its words.
     */
    public function testAPlainTextReplyLosesItsQuoteAndAttribution(): void
    {
        $message           = new Message();
        $message->bodyText = "Passt!\n\nAm 19.10.2026 um 15:25 schrieb Sven <sven@example.test>:\n"
            . "> Hallo Paul, hier ist der lange Text meiner eigenen Mail an dich.\n> Gruß Sven";

        self::assertSame('Passt!', $this->snippet->of($message));
    }

    public function testAPlainTextOutlookReplyLosesItsHeaderBlock(): void
    {
        $message           = new Message();
        $message->bodyText = "Ja, anbei.\n\nVon: Sven <sven@example.test>\nGesendet: Montag, 19. Oktober 2026\n"
            . "An: Paul\nBetreff: Budget\n\nHallo Paul, wo liegt die Datei mit dem Budget?";

        self::assertSame('Ja, anbei.', $this->snippet->of($message));
    }

    /** "Von:" opening a sentence is not a header block. */
    public function testASentenceStartingWithVonIsKept(): void
    {
        $message           = new Message();
        $message->bodyText = "Von: dir hätte ich das nicht erwartet.\nAber danke!";

        self::assertSame('Von: dir hätte ich das nicht erwartet. Aber danke!', $this->snippet->of($message));
    }

    /**
     * A body that is all quote — a forward without a comment — keeps it.
     * Cutting would leave an empty row, and the forwarded mail is the
     * message here.
     */
    public function testABodyThatIsOnlyAQuoteIsKept(): void
    {
        $message           = new Message();
        $message->bodyText = "> Die Rechnung liegt bei.\n> Gruß Sven";

        self::assertSame('> Die Rechnung liegt bei. > Gruß Sven', $this->snippet->of($message));
    }

    /** The signature goes at its `-- ` line, in text and in HTML. */
    public function testTheSignatureIsCutAtItsDelimiter(): void
    {
        $text           = new Message();
        $text->bodyText = "Passt.\n\n-- \nPaul Müller | Geschäftsführer | Beispiel GmbH, Musterstraße 1, Berlin";

        $html               = new Message();
        $html->bodyHtmlSafe = '<p>Passt.</p><div>--</div><div>Paul Müller | Geschäftsführer | Beispiel GmbH, Musterstraße 1, Berlin</div>';

        self::assertSame('Passt.', $this->snippet->of($text));
        self::assertSame('Passt.', $this->snippet->of($html));
    }

    /**
     * A person's mail is not skipped into, which is why skipping is for bulk
     * mail only. A short message over a long signature line WITHOUT a `--`
     * is ordinary business mail, and skipping to the first long block
     * previewed the company address instead of the message.
     */
    public function testAPersonsMailStartsAtItsFirstLine(): void
    {
        foreach ([null, MessageCategory::Primary, MessageCategory::Forums] as $category) {
            $message               = new Message();
            $message->category     = $category;
            $message->bodyHtmlSafe = '<p>Hallo Sven,</p><p>passt.</p>'
                . '<p>Paul Müller | Geschäftsführer | Beispiel GmbH, Musterstraße 1, Berlin</p>';

            self::assertSame(
                'Hallo Sven, passt. Paul Müller | Geschäftsführer | Beispiel GmbH, Musterstraße 1, Berlin',
                $this->snippet->of($message),
                null === $category ? 'uncategorised' : $category->value,
            );
        }
    }

    /** The list's own path — columns, no entity — reads the category too. */
    public function testTheColumnsAloneGiveTheSamePreview(): void
    {
        $html = '<div>View in browser</div><p>Your parcel is on its way and should reach you on Friday afternoon.</p>';

        self::assertSame(
            'Your parcel is on its way and should reach you on Friday afternoon.',
            $this->snippet->fromBodies($html, null, MessageCategory::Updates),
        );
        self::assertSame(
            'View in browser Your parcel is on its way and should reach you on Friday afternoon.',
            $this->snippet->fromBodies($html, null, MessageCategory::Primary),
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

    /**
     * An encrypted message has no body here to preview, and the row says so
     * with a lock in front of the subject now (reported from the milestone
     * build): the placeholder this used to put in the preview, "Encrypted
     * message", is gone, and the preview is simply empty.
     */
    public function testAnEncryptedMessageWithNoBodyHasNoPlaceholderPreview(): void
    {
        $message         = new Message();
        $message->crypto = MessageCrypto::PgpEncrypted;

        self::assertSame('', $this->snippet->of($message));
        self::assertSame('', $this->snippet->fromBodies(null, null));
    }

    /** One whose decrypted copy is kept on the server previews what it says. */
    public function testAnEncryptedMessageKeptReadableShowsItsRealPreview(): void
    {
        $message           = new Message();
        $message->crypto   = MessageCrypto::PgpEncrypted;
        $message->bodyText = 'The pumpkins arrive on Tuesday.';

        self::assertSame('The pumpkins arrive on Tuesday.', $this->snippet->of($message));
    }
}
