<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\QuoteCollapser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bug this guards against is asymmetric: leaving a quote visible is a shrug,
 * but hiding the sender's own new text is a data-loss-shaped bug. So the cases
 * split in two — the reply families that MUST collapse (new text kept verbatim,
 * quote wrapped and hidden), and the shapes that must be left completely alone.
 *
 * Every fixture here is the sanitizer's OUTPUT, not a raw client body: class,
 * and sometimes id, are already gone (see RemoteContentBlockerTest for why the
 * collapser sees post-sanitize markup), so detection stands on text and
 * structure, which is exactly what these strings exercise.
 */
final class QuoteCollapserTest extends TestCase
{
    private QuoteCollapser $collapser;

    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'message.quote.show' => 'Show quoted text',
                'message.quote.hide' => 'Hide quoted text',
                default              => $id,
            },
        );

        $this->collapser = new QuoteCollapser($translator);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     *                new-text, quoted-text — one reply family per row
     */
    public static function replyFamilies(): iterable
    {
        yield 'gmail' => [
            '<div dir="ltr">Thanks, that works for me.</div>'
            . '<div><div dir="ltr">On Mon, Aug 11, 2026 at 9:14 AM Jane Roe &lt;jane@example.com&gt; wrote:</div>'
            . '<blockquote style="border-left:1px solid #ccc"><div>Are we still on for Tuesday?</div></blockquote></div>',
            'Thanks, that works for me.',
            'Are we still on for Tuesday?',
        ];

        yield 'apple-mail generic blockquote' => [
            '<div>Sounds good.</div><div><br></div>'
            . '<div>On 11 Aug 2026, at 09:14, Jane Roe &lt;jane@example.com&gt; wrote:</div>'
            . '<blockquote type="cite"><div>Is the room booked?</div></blockquote>',
            'Sounds good.',
            'Is the room booked?',
        ];

        yield 'outlook divRplyFwdMsg header block' => [
            '<div><p>Yes, attached.</p></div>'
            . '<div id="divRplyFwdMsg" dir="ltr"><hr>'
            . '<p><b>From:</b> Jane Roe &lt;jane@example.com&gt;<br>'
            . '<b>Sent:</b> Monday, August 11, 2026 9:14 AM<br>'
            . '<b>To:</b> John Doe &lt;john@example.com&gt;<br>'
            . '<b>Subject:</b> Re: Budget</p>'
            . '<div><p>Where is the budget file?</p></div></div>',
            'Yes, attached.',
            'Where is the budget file?',
        ];

        yield 'german attribution' => [
            '<div>Passt, danke!</div>'
            . '<div>Am 11.08.2026 um 09:14 schrieb Jane Roe &lt;jane@example.com&gt;:</div>'
            . '<blockquote><div>Sehen wir uns am Dienstag?</div></blockquote>',
            'Passt, danke!',
            'Sehen wir uns am Dienstag?',
        ];

        yield 'gmail forwarded divider' => [
            '<div>FYI — see the thread below.</div>'
            . '<div>---------- Forwarded message ---------<br>From: Jane Roe</div>'
            . '<blockquote><div>The original announcement.</div></blockquote>',
            'FYI — see the thread below.',
            'The original announcement.',
        ];

        yield 'outlook original message divider' => [
            '<div>My answer is below.</div>'
            . '<div>-----Original Message-----<br>From: Jane Roe</div>'
            . '<blockquote><div>The question that was asked.</div></blockquote>',
            'My answer is below.',
            'The question that was asked.',
        ];
    }

    #[DataProvider('replyFamilies')]
    public function testReplyHistoryIsCollapsed(string $html, string $newText, string $quotedText): void
    {
        $out = $this->collapser->collapse($html);

        self::assertStringContainsString('data-plmail-quote-toggle', $out, 'a toggle button is rendered');
        self::assertNotSame($html, $out, 'the body was transformed');

        // The sender's own words survive verbatim, and they are NOT inside the
        // hidden wrapper — this is the whole safety property.
        self::assertStringContainsString($newText, $out, 'new text survives verbatim');
        self::assertStringContainsString($quotedText, $out, 'quoted text survives verbatim');
        self::assertStringNotContainsString($newText, self::hiddenRegion($out), 'new text is not hidden');
        self::assertStringContainsString($quotedText, self::hiddenRegion($out), 'quoted text is what got hidden');

        // The toggle precedes the region it controls, and the region is hidden.
        self::assertMatchesRegularExpression(
            '/data-plmail-quote-toggle.*?<div data-plmail-quote(?:=""|)\s+hidden/s',
            $out,
            'the hidden wrapper follows the toggle',
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function leftAlone(): iterable
    {
        yield 'no quote at all' => [
            '<div><p>Hi team, here is the full report.</p><p>Cheers,<br>John</p></div>',
        ];

        // A blockquote used as a genuine quotation, mid-body, with the sender's
        // own text on BOTH sides and no attribution line: indistinguishable from
        // reply history only to a careless detector.
        yield 'blockquote is the actual content, not a reply' => [
            '<div><p>My favourite line:</p>'
            . '<blockquote>To be, or not to be.</blockquote>'
            . '<p>What do you all make of it?</p></div>',
        ];

        // A comment-free forward: the whole body is quote, so there is no new
        // text to protect and nothing worth a toggle.
        yield 'pure quote, no new text before the seam' => [
            '<div>On Mon, Aug 11, 2026 at 9:14 AM Jane wrote:</div>'
            . '<blockquote><div>Nothing of mine sits above this.</div></blockquote>',
        ];

        // "On Monday I wrote up the notes:" — an attribution-shaped sentence
        // that introduces no quote. Must not be mistaken for a reply boundary.
        yield 'attribution-shaped prose with no blockquote' => [
            '<div><p>On Monday I wrote up the notes: they are attached.</p>'
            . '<p>Let me know what you think.</p></div>',
        ];
    }

    #[DataProvider('leftAlone')]
    public function testAmbiguousOrQuoteFreeBodiesAreUntouched(string $html): void
    {
        $out = $this->collapser->collapse($html);

        self::assertSame($html, $out, 'the body is returned byte-for-byte unchanged');
        self::assertStringNotContainsString('data-plmail-quote-toggle', $out, 'no button is rendered');
    }

    public function testTheToggleIsAnAccessibleButton(): void
    {
        $out = $this->collapser->collapse(
            '<div>Reply.</div>'
            . '<div>On 11 Aug 2026, at 09:14, Jane wrote:</div>'
            . '<blockquote><div>Quoted.</div></blockquote>',
        );

        self::assertStringContainsString('<button type="button"', $out);
        self::assertStringContainsString('aria-expanded="false"', $out);
        self::assertStringContainsString('aria-label="Show quoted text"', $out);
        self::assertStringContainsString('data-label-hide="Hide quoted text"', $out);
    }

    public function testCollapsingIsIdempotent(): void
    {
        $html = '<div>Reply.</div>'
            . '<div>On 11 Aug 2026, at 09:14, Jane wrote:</div>'
            . '<blockquote><div>Quoted.</div></blockquote>';

        $once  = $this->collapser->collapse($html);
        $twice = $this->collapser->collapse($once);

        self::assertSame($once, $twice, 'a body already carrying a toggle is left as-is');
        self::assertSame(1, substr_count($twice, 'data-plmail-quote-toggle'), 'exactly one toggle');
    }

    public function testEmptyBodyIsReturnedUnchanged(): void
    {
        self::assertSame('', $this->collapser->collapse(''));
        self::assertSame('   ', $this->collapser->collapse('   '));
    }

    /** The serialized contents of the hidden [data-plmail-quote] wrapper. */
    private static function hiddenRegion(string $html): string
    {
        // The wrapper is the last thing in the body and runs to the end, so from
        // its opening tag onward is the hidden region. The `=` distinguishes the
        // wrapper's `data-plmail-quote=""` from the button's
        // `data-plmail-quote-toggle`.
        $pos = strpos($html, 'data-plmail-quote=');

        return false === $pos ? '' : substr($html, $pos);
    }
}
