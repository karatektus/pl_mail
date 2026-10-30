<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\QuotedHistory;
use App\Domain\Helper\ReadableBody;
use App\Entity\Mail\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where a reply's own words end and the mail it answers begins.
 *
 * The cases are the shapes real clients produce, because the bug this guards
 * (#437) came from the shapes the old line filter did not know: HTML history
 * in a container, and Outlook's plain text under a header block, neither
 * marked with `>`. Each "history" case carries a date-and-time in the quoted
 * part, so a regression is a calendar card, not just a longer string.
 *
 * The cases that keep text matter as much: an interleaved reply puts its
 * answers between the quoted lines, and a "Datum:" line on its own is how
 * German business mail states the appointment this exists to find.
 */
final class QuotedHistoryTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function plainTextCases(): iterable
    {
        yield 'an Outlook reply under a German header block' => [
            "Danke, passt!\n\n________________________________\nVon: Paul Lützner <paul@example.org>\n"
            . "Gesendet: Mittwoch, 28. Oktober 2026 10:00\nAn: me@example.test\nBetreff: Treffen\n\n"
            . 'Treffen wir uns am 05.11.2026 um 14 Uhr?',
            "Danke, passt!\n\n________________________________",
        ];

        yield 'an Apple Mail forward, with the date two lines down' => [
            "FYI\n\nFrom: Paul <paul@example.org>\nSubject: Meeting\nDate: 28 October 2026 at 10:00:00 CET\nTo: me@example.test\n\n"
            . 'Shall we meet on 5 November 2026 at 2pm?',
            'FYI',
        ];

        yield 'an Outlook reply under its separator' => [
            "Thanks!\n\n-----Original Message-----\nFrom: Paul <paul@example.org>\nSent: Wednesday, October 28, 2026 10:00 AM\n\n"
            . 'Shall we meet on 5 November 2026 at 2pm?',
            'Thanks!',
        ];

        yield 'a forward' => [
            "FYI\n\n---------- Weitergeleitete Nachricht ---------\nVon: Paul <paul@example.org>\nDatum: Mi., 28. Okt. 2026\n\n"
            . 'Termin: 05.11.2026 um 14 Uhr',
            'FYI',
        ];

        yield 'an attribution with unquoted history under it' => [
            "Super, danke!\n\nAm Mi., 28. Okt. 2026 um 10:00 Uhr schrieb Paul <paul@example.org>:\n"
            . 'Treffen wir uns am 05.11.2026 um 14 Uhr?',
            'Super, danke!',
        ];

        yield 'an attribution wrapped at seventy-two columns' => [
            "Sounds good.\n\nOn Wed, Oct 28, 2026 at 10:00 AM Paul Lützner\n<paul@example.org> wrote:\n"
            . 'Shall we meet on 5 November 2026 at 2pm?',
            'Sounds good.',
        ];

        yield 'Gmail on Android, verb first' => [
            "Ok!\n\nPaul <paul@example.org> schrieb am Mi., 28. Okt. 2026, 10:00:\nAm 05.11.2026 um 14 Uhr?",
            'Ok!',
        ];
    }

    /**
     * The sender's answer opening with the same word as the attribution under
     * it. Taking it for the top of a wrapped attribution cut the date being
     * proposed along with the history.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function answersAboveAnAttribution(): iterable
    {
        yield 'English' => [
            "On Thursday 5 November 2026 at 2pm works for me.\n\nOn Wed, Oct 28, 2026 at 10:00 AM Paul <paul@example.org> wrote:\n> When suits you?",
            'On Thursday 5 November 2026 at 2pm works for me.',
        ];

        yield 'German' => [
            "Am 05.11.2026 um 14 Uhr passt mir.\n\nAm Mi., 28. Okt. 2026 um 10:00 Uhr schrieb Paul <paul@example.org>:\n> Wann?",
            'Am 05.11.2026 um 14 Uhr passt mir.',
        ];

        yield 'French' => [
            "Le 5 novembre 2026 à 14h me convient.\n\nLe mer. 28 oct. 2026 à 10:00, Paul <paul@example.org> a écrit :\n> Quand ?",
            'Le 5 novembre 2026 à 14h me convient.',
        ];

        yield 'no punctuation, but a blank line between' => [
            "Am 05.11.2026 um 14 Uhr\n\nPaul <paul@example.org> schrieb am Mi., 28. Okt. 2026, 10:00:\n> Wann?",
            'Am 05.11.2026 um 14 Uhr',
        ];

        yield 'a sentence directly above a verb-first attribution' => [
            "Am 05.11.2026 um 14 Uhr passt mir.\nPaul <paul@example.org> schrieb am Mi., 28. Okt. 2026, 10:00:\n> Wann?",
            'Am 05.11.2026 um 14 Uhr passt mir.',
        ];
    }

    #[DataProvider('answersAboveAnAttribution')]
    public function testAnAnswerAboveAnAttributionIsKept(string $mail, string $ownWords): void
    {
        self::assertSame($ownWords, QuotedHistory::fromText($mail));
    }

    public function testAGmailAnswerOpeningLikeItsAttributionIsKept(): void
    {
        $html = '<div dir="ltr">On Thursday 5 November 2026 at 2pm works for me.</div><br><div class="gmail_quote"><div class="gmail_attr">'
            . 'On Wed, Oct 28, 2026 at 10:00 AM Paul &lt;paul@example.org&gt; wrote:<br></div>'
            . '<blockquote class="gmail_quote"><div>When?</div></blockquote></div>';

        self::assertSame('On Thursday 5 November 2026 at 2pm works for me.', $this->ownWordsOfHtml($html));
    }

    #[DataProvider('plainTextCases')]
    public function testTheHistoryIsCutFromPlainText(string $mail, string $ownWords): void
    {
        self::assertSame($ownWords, QuotedHistory::fromText($mail));
    }

    /** @return iterable<string, array{string}> */
    public static function untouchedTextCases(): iterable
    {
        yield 'a mail with no history' => ["Hallo,\n\nTermin wie vereinbart: 04.08.2026 um 14 Uhr\n\nViele Grüße"];

        // The answers between the quotes are the sender's; the quoted lines
        // are left to the detector's own `>` filter.
        yield 'an interleaved reply' => [
            "On Wed, Oct 28, 2026 at 10:00 AM Paul <paul@example.org> wrote:\n> When suits you?\n"
            . "Thursday 5 November 2026 at 2pm\n> And where?\nMy office",
        ];

        yield 'a Datum line stating the appointment' => ["Hallo,\n\nDatum: 4. August 2026, 14 Uhr\nOrt: Büro"];

        yield 'a Von line that is prose rather than a header block' => [
            "Von: der Personalabteilung kam die Zusage.\nTermin ist am 04.08.2026 um 14 Uhr.",
        ];

        // A form's mail: headers of a kind, and no time a mail was sent at.
        yield 'a web form with From and Subject above its message' => [
            "From: Anna\nSubject: Viewing\nMessage: Can we meet on 05.11.2026 at 14:00?",
        ];

        yield 'a sentence that mentions writing' => ["Wie Paul schrieb:\nTermin am 04.08.2026 um 14 Uhr."];
    }

    #[DataProvider('untouchedTextCases')]
    public function testOwnWordsAreLeftAlone(string $mail): void
    {
        self::assertSame($mail, QuotedHistory::fromText($mail));
    }

    public function testAGmailReplyLosesTheQuoteButKeepsTheAnswer(): void
    {
        $html = '<div dir="ltr">Super, danke!</div><br><div class="gmail_quote"><div dir="ltr" class="gmail_attr">'
            . 'Am Mi., 28. Okt. 2026 um 10:00 Uhr schrieb Paul &lt;<a href="mailto:paul@example.org">paul@example.org</a>&gt;:<br></div>'
            . '<blockquote class="gmail_quote" style="margin:0 0 0 .8ex"><div dir="ltr">Treffen wir uns am 05.11.2026 um 14 Uhr?</div>'
            . '</blockquote></div>';

        self::assertSame('Super, danke!', $this->ownWordsOfHtml($html));
    }

    public function testAGmailForwardIsCutAtItsSeparator(): void
    {
        $html = '<div dir="ltr">FYI</div><br><div class="gmail_quote"><div dir="ltr" class="gmail_attr">'
            . '---------- Forwarded message ---------<br>From: <b>Paul</b> &lt;paul@example.org&gt;<br>'
            . 'Date: Wed, Oct 28, 2026 at 10:00 AM<br>Subject: Meeting<br></div><br><br>'
            . '<div dir="ltr">Shall we meet on 5 November 2026 at 2pm?</div></div>';

        self::assertSame('FYI', $this->ownWordsOfHtml($html));
    }

    /** Dutch headers, which the text pass does not know: the id is what catches it. */
    public function testAnOutlookReplyIsCutAtItsMarkerWhateverTheLanguage(): void
    {
        $html = '<html><head><style>p { margin: 0 }</style></head><body><div>Bedankt!</div>'
            . '<div id="appendonsend"></div><hr style="display:inline-block;width:98%">'
            . '<div id="divRplyFwdMsg" dir="ltr"><b>Van:</b> Paul &lt;paul@example.org&gt;<br><b>Verzonden:</b> woensdag 28 oktober 2026 10:00</div>'
            . '<div>Zullen we op 05.11.2026 om 14:00 afspreken?</div></body></html>';

        self::assertSame('Bedankt!', $this->ownWordsOfHtml($html));
    }

    public function testAnOutlookMarkerInsideAWrapperTakesTheWrappersNextSiblingsToo(): void
    {
        $html = '<div class="WordSection1"><p>Danke!</p><div id="divRplyFwdMsg">Von: Paul</div></div>'
            . '<div>Termin: 05.11.2026 um 14 Uhr</div>';

        self::assertSame('Danke!', $this->ownWordsOfHtml($html));
    }

    /** Apple Mail and Thunderbird: an attribution, then a cited blockquote. */
    public function testAReplyWrittenBelowTheQuoteIsKept(): void
    {
        $html = '<div>Am 28.10.2026 um 10:00 schrieb Paul &lt;paul@example.org&gt;:</div>'
            . '<blockquote type="cite"><div>Wann passt es dir?</div></blockquote>'
            . '<div>Am 05.11.2026 um 14 Uhr.</div>';

        self::assertSame(
            "Am 28.10.2026 um 10:00 schrieb Paul <paul@example.org>:\n>\nAm 05.11.2026 um 14 Uhr.",
            $this->ownWordsOfHtml($html),
            'the answer under the quote is the sender\'s; the attribution and the marker are the detector\'s to drop',
        );
    }

    public function testHtmlWithoutHistoryIsReturnedAsItWas(): void
    {
        $html = '<p>Termin: 04.08.2026 um 14 Uhr</p>';

        self::assertSame($html, QuotedHistory::fromHtml($html));
    }

    /**
     * Graph stores its 255-character preview as the text of an HTML mail, and
     * the preview of a short reply IS the history. The HTML is read instead,
     * and an empty answer stays empty rather than falling back to the preview.
     */
    public function testAGraphReplyIsReadFromItsHtmlAndNeverFromThePreview(): void
    {
        $message = new Message();
        $message->graphId  = 'AAMk-graph-id';
        $message->bodyText = 'Danke! Von: Paul Gesendet: Mittwoch, 28. Oktober 2026 Treffen wir uns am 05.11.2026 um 14 Uhr?';
        $message->bodyHtml = '<div id="appendonsend"></div><div id="divRplyFwdMsg">Von: Paul</div>'
            . '<div>Treffen wir uns am 05.11.2026 um 14 Uhr?</div>';

        self::assertSame('', ReadableBody::ownWordsOf($message));
    }

    public function testThePlainPartIsPreferredWhenThereIsOne(): void
    {
        $message = new Message();
        $message->bodyText = "Danke!\n\n-----Ursprüngliche Nachricht-----\nVon: Paul\nGesendet: Mittwoch\n\nAm 05.11.2026 um 14 Uhr?";
        $message->bodyHtml = '<p>something else</p>';

        self::assertSame('Danke!', ReadableBody::ownWordsOf($message));
    }

    private function ownWordsOfHtml(string $html): string
    {
        $message = new Message();
        $message->bodyHtml = $html;

        return ReadableBody::ownWordsOf($message);
    }
}
