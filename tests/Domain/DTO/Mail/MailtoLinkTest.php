<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Mail;

use App\Domain\DTO\Mail\MailtoLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading a `mailto:` link the way the Android and iOS apps read it.
 *
 * The cases are the ones each of those parsers was written around, because a
 * link that opens a different draft on the web than on the phone is a bug in
 * one of them: `+` that must stay a plus, a comma that is content, a German
 * subject in multibyte escapes, and the headers a link must not be able to set.
 */
final class MailtoLinkTest extends TestCase
{
    public function testSomethingThatIsNotAMailtoIsNothingRatherThanAnEmptyMessage(): void
    {
        self::assertNull(MailtoLink::parse('https://example.org/?to=a@example.org'));
        self::assertNull(MailtoLink::parse(''));
        self::assertNull(MailtoLink::parse(null));
    }

    public function testABareMailtoIsABlankMessage(): void
    {
        $link = MailtoLink::parse('mailto:');

        self::assertNotNull($link);
        self::assertSame([], $link->to);
        self::assertSame('', $link->subject);
        self::assertSame('', $link->bodyHtml());
    }

    public function testEveryFieldALinkMaySetArrives(): void
    {
        $link = MailtoLink::parse(
            'MAILTO:ada@example.org,bob@example.org?Cc=carol@example.org&bcc=dave@example.org'
            . '&to=erin@example.org&subject=Hello%20there&body=Line%20one%0D%0ALine%20two',
        );

        self::assertNotNull($link);
        self::assertSame(
            ['ada@example.org', 'bob@example.org', 'erin@example.org'],
            array_column($link->to, 'address'),
            'the addresses before the ? and the to= ones are one list',
        );
        self::assertSame(['carol@example.org'], array_column($link->cc, 'address'));
        self::assertSame(['dave@example.org'], array_column($link->bcc, 'address'));
        self::assertSame('Hello there', $link->subject);
        self::assertSame("Line one\nLine two", $link->body);
    }

    public function testAPlusIsAPlusRatherThanASpace(): void
    {
        $link = MailtoLink::parse('mailto:anna+lists@example.org?subject=1+1');

        self::assertSame(['anna+lists@example.org'], array_column($link->to ?? [], 'address'));
        self::assertSame('1+1', $link?->subject);
    }

    public function testAnEncodedCommaInsideANameDoesNotSplitTheRecipient(): void
    {
        $link = MailtoLink::parse('mailto:%22Doe%2C%20John%22%20%3Cjohn@example.org%3E');

        self::assertSame([['name' => 'Doe, John', 'address' => 'john@example.org']], $link?->to);
    }

    public function testMultibyteEscapesDecodeToOneCharacter(): void
    {
        self::assertSame('Grüße', MailtoLink::parse('mailto:?subject=Gr%C3%BC%C3%9Fe')?->subject);
    }

    public function testAPercentSignThatIsNotAnEscapeIsKept(): void
    {
        self::assertSame('100% sure', MailtoLink::parse('mailto:?subject=100%%20sure')?->subject);
    }

    public function testBrokenUtf8OpensAComposerRatherThanFailing(): void
    {
        $subject = MailtoLink::parse('mailto:?subject=caf%E9')?->subject;

        self::assertNotNull($subject);
        self::assertTrue(mb_check_encoding($subject, 'UTF-8'));
        self::assertStringStartsWith('caf', $subject);
    }

    /**
     * Headers that would let a page the reader visited write in somebody
     * else's name, or into somebody else's conversation.
     */
    #[DataProvider('foreignHeaders')]
    public function testHeadersALinkMustNotSetAreIgnored(string $header): void
    {
        $link = MailtoLink::parse(sprintf('mailto:ada@example.org?%s=boss@example.org&subject=Hi', $header));

        self::assertNotNull($link);
        self::assertSame(['ada@example.org'], array_column($link->to, 'address'));
        self::assertSame([], $link->cc);
        self::assertSame([], $link->bcc);
        self::assertSame('Hi', $link->subject);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignHeaders(): iterable
    {
        yield 'from'        => ['from'];
        yield 'reply-to'    => ['reply-to'];
        yield 'sender'      => ['sender'];
        yield 'in-reply-to' => ['in-reply-to'];
    }

    public function testWhatIsNotAnAddressIsDroppedAndTheRestKept(): void
    {
        $link = MailtoLink::parse('mailto:not-an-address,ada@example.org,ADA@example.org');

        self::assertSame(['ada@example.org'], array_column($link->to ?? [], 'address'), 'and the same address twice is one');
    }

    public function testACraftedLinkCannotAddressThousands(): void
    {
        $addresses = implode(',', array_map(static fn (int $i): string => sprintf('u%d@example.org', $i), range(1, 500)));

        self::assertCount(50, MailtoLink::parse('mailto:' . $addresses)->to ?? []);
    }

    public function testASubjectIsOneLine(): void
    {
        self::assertSame('One two', MailtoLink::parse('mailto:?subject=One%0D%0Atwo')?->subject);
    }

    public function testTheBodyBecomesEscapedParagraphs(): void
    {
        $link = MailtoLink::parse('mailto:?body=%3Cb%3Ehi%3C%2Fb%3E%0A%0Abye');

        self::assertSame('<p>&lt;b&gt;hi&lt;/b&gt;</p><p><br></p><p>bye</p>', $link?->bodyHtml());
    }
}
