<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Service\Mail\InlineAttachmentLinker;
use App\Service\Mail\InlineAttachmentSigner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The reading frame has no session cookie, so an inline image has to be
 * reachable by a signed URL (#213) — but only the images of the message being
 * read.
 *
 * The stored body is the sender's HTML and the sanitizer lets any
 * `/mail/attachment/<digits>` through, so a sender can name somebody else's
 * attachment. The case that matters most below is the one where the id is not
 * one of this message's parts: it must come out untouched.
 */
final class InlineAttachmentLinkerTest extends KernelTestCase
{
    private InlineAttachmentLinker $linker;
    private InlineAttachmentSigner $signer;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->linker = static::getContainer()->get(InlineAttachmentLinker::class);
        $this->signer = static::getContainer()->get(InlineAttachmentSigner::class);
    }

    public function testAnImageOfThisMessageGetsItsSignedUrl(): void
    {
        $message = $this->message([101 => 'image/png']);

        $html = $this->linker->link('<img src="/mail/attachment/101" width="16">', $message);

        self::assertSame(
            '<img src="' . $this->signed(101) . '" width="16">',
            $html,
        );
        self::assertStringStartsWith('<img src="/mail/inline/101?e=', (string) $html);
    }

    public function testAnIdThatIsNotThisMessagesIsLeftBehindTheLogin(): void
    {
        $message = $this->message([101 => 'image/png']);

        $html = '<img src="/mail/attachment/999">';

        self::assertSame($html, $this->linker->link($html, $message));
    }

    public function testNothingThatIsNotARasterImageIsSigned(): void
    {
        $message = $this->message([
            101 => 'image/svg+xml',
            102 => 'application/pdf',
            103 => 'text/html',
        ]);

        $html = '<img src="/mail/attachment/101"><img src="/mail/attachment/102"><img src="/mail/attachment/103">';

        self::assertSame($html, $this->linker->link($html, $message));
    }

    /**
     * Vaultwarden's logo is `image/octet-stream`, and `application/octet-stream`
     * is common too (#213). Whether the bytes are an image is the route's call,
     * when it is fetched; rendering must not read the file.
     */
    public function testAnUnlabelledPartIsSignedForTheRouteToSniff(): void
    {
        $message = $this->message([101 => 'image/octet-stream', 102 => 'application/octet-stream']);

        $html = $this->linker->link('<img src="/mail/attachment/101"><img src="/mail/attachment/102">', $message);

        self::assertSame(
            '<img src="' . $this->signed(101) . '"><img src="' . $this->signed(102) . '">',
            $html,
        );
    }

    /** A click on a link comes from the top-level page, which has the session. */
    public function testALinkKeepsTheAuthenticatedUrl(): void
    {
        $message = $this->message([101 => 'image/png']);

        $html = '<a href="/mail/attachment/101">logo.png</a>';

        self::assertSame($html, $this->linker->link($html, $message));
    }

    public function testOnlyTheBareImageUrlIsRewritten(): void
    {
        $message = $this->message([101 => 'image/png']);

        $html = '<a href="/mail/attachment/101?download=1">a</a><a href="/mail/attachment/101/preview">b</a>';

        self::assertSame($html, $this->linker->link($html, $message));
    }

    public function testABackgroundImageInAStyleIsSignedToo(): void
    {
        $message = $this->message([101 => 'image/gif']);
        $signed  = $this->signed(101);

        $html = $this->linker->link('<td style="background:url(/mail/attachment/101)"></td>', $message);
        self::assertStringContainsString('url(' . $signed . ')', (string) $html);

        $html = $this->linker->link('<td style="background:url(&quot;/mail/attachment/101&quot;)"></td>', $message);
        self::assertStringContainsString('url(&quot;' . $signed . '&quot;)', (string) $html);
    }

    public function testABodyWithoutInlineImagesComesBackAsItWas(): void
    {
        $message = $this->message([101 => 'image/png']);

        self::assertNull($this->linker->link(null, $message));
        self::assertSame('<p>hi</p>', $this->linker->link('<p>hi</p>', $message));
    }

    public function testASignatureIsForOnePartAndOneExpiry(): void
    {
        $expires = time() + 3600;

        self::assertTrue($this->signer->isValid(101, $expires, $this->signer->sign(101, $expires)));
        self::assertFalse($this->signer->isValid(102, $expires, $this->signer->sign(101, $expires)), 'not transferable between parts');
        self::assertFalse($this->signer->isValid(101, $expires + 86400, $this->signer->sign(101, $expires)), 'the expiry cannot be pushed out');
        self::assertFalse($this->signer->isValid(101, $expires, ''));
    }

    /**
     * A link leaked through a forward or a log must stop working (#213). The
     * expiry is the end of the next UTC day, so every view on one day mints the
     * same URL and the browser cache keeps answering.
     */
    public function testALinkWorksForOneToTwoDaysAndIsTheSameAllDay(): void
    {
        $clock  = new MockClock('2026-10-19 00:00:01 UTC');
        $signer = $this->signerAt($clock);

        $url = $signer->urlFor(101);
        $clock->modify('2026-10-19 23:59:59 UTC');
        self::assertSame($url, $signer->urlFor(101), 'the same URL all day, so the browser can cache it');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expires   = (int) $query['e'];
        $signature = (string) $query['s'];

        self::assertSame(strtotime('2026-10-21 00:00:00 UTC'), $expires);

        $clock->modify('2026-10-20 23:59:59 UTC');
        self::assertTrue($signer->isValid(101, $expires, $signature));

        $clock->modify('2026-10-21 00:00:00 UTC');
        self::assertFalse($signer->isValid(101, $expires, $signature), 'expired');
    }

    /** The signed URL as it stands in HTML: `&` escaped. */
    private function signed(int $partId): string
    {
        return htmlspecialchars($this->signer->urlFor($partId), ENT_QUOTES | ENT_HTML5);
    }

    private function signerAt(MockClock $clock): InlineAttachmentSigner
    {
        return new InlineAttachmentSigner(
            static::getContainer()->get(UrlGeneratorInterface::class),
            $clock,
            (string) static::getContainer()->getParameter('kernel.secret'),
        );
    }

    /**
     * @param array<int, string> $parts part id => content type
     */
    private function message(array $parts): Message
    {
        $message = new Message();

        foreach ($parts as $id => $contentType) {
            $part              = new MessagePart();
            $part->contentType = $contentType;

            // The id is generated by the database; nothing here needs one.
            new \ReflectionProperty(MessagePart::class, 'id')->setValue($part, $id);

            $message->addMessagePart($part);
        }

        return $message;
    }
}
