<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Mime;

use App\Service\Mail\MailBodySanitizer;
use App\Service\Mail\Mime\DataImageEmbedder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mime\Email;

/**
 * A `data:` image in a body leaves as an attached inline part.
 *
 * The assertions are made on the serialised mail wherever they can be, because
 * that is what a recipient's client is handed: a part with a Content-ID, a
 * body that points at it, and no data URI left for Gmail to discard.
 */
final class DataImageEmbedderTest extends TestCase
{
    /** A one-pixel PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private DataImageEmbedder $embedder;

    protected function setUp(): void
    {
        $this->embedder = new DataImageEmbedder();
    }

    public function testADataImageBecomesAnInlinePartTheBodyPointsAt(): void
    {
        $email = $this->email();
        $html  = $this->embedder->embed($email, '<p>Paul</p><img src="data:image/png;base64,' . self::PNG . '" alt="logo" width="40">');

        self::assertStringNotContainsString('data:image', $html);
        self::assertMatchesRegularExpression('~<img src="cid:inline-[0-9a-f]{16}\.png" alt="logo" width="40">~', $html);
        self::assertCount(1, $email->getAttachments());

        $wire = $email->html($html)->toString();

        self::assertStringContainsString('multipart/related', $wire);
        self::assertStringContainsString('Content-Type: image/png', $wire);
        self::assertStringContainsString('Content-Disposition: inline', $wire);
        self::assertStringNotContainsString('data:image', $wire);
    }

    public function testTheSameImageUsedTwiceIsAttachedOnce(): void
    {
        $email = $this->email();
        $image = '<img src="data:image/png;base64,' . self::PNG . '">';
        $html  = $this->embedder->embed($email, $image . '<p>and again</p>' . $image);

        self::assertCount(1, $email->getAttachments());
        self::assertSame(2, substr_count($html, 'cid:inline-'));
    }

    /** Base64 out of a generator is often wrapped; the image is the same one. */
    public function testWrappedBase64AndSingleQuotesAreUnderstood(): void
    {
        $email = $this->email();
        $html  = $this->embedder->embed(
            $email,
            "<img alt='x' src='data:image/png;base64," . chunk_split(self::PNG, 20, "\n") . "'>",
        );

        self::assertStringContainsString("src='cid:inline-", $html);
        self::assertSame(base64_decode(self::PNG), $email->getAttachments()[0]->getBody());
    }

    /**
     * The form every stored body is actually in. The sanitiser entity-encodes
     * attribute values, so the padding arrives as `&#61;` and a plus as
     * `&#43;` — and an embedder that only knew raw base64 embedded nothing
     * that had been saved.
     */
    public function testAnImageThatHasBeenThroughTheSanitiserIsStillEmbedded(): void
    {
        $sanitizer = new MailBodySanitizer(self::createStub(UrlGeneratorInterface::class), new NullLogger());

        // 0xFB 0xEF 0xBE encodes to "++++", and one byte more leaves padding.
        $bytes     = "\xFB\xEF\xBE\xFB";
        $sanitized = $sanitizer->sanitizeComposedBody('<img src="data:image/png;base64,' . base64_encode($bytes) . '" alt="logo">');

        self::assertStringContainsString('&#61;', $sanitized, 'the fixture is in the encoded form this is about');

        $email = $this->email();
        $html  = $this->embedder->embed($email, $sanitized);

        self::assertStringContainsString('src="cid:inline-', $html);
        self::assertSame($bytes, $email->getAttachments()[0]->getBody());
    }

    public function testWhatIsNotAnEmbeddableImageIsLeftExactlyAsItWas(): void
    {
        $email = $this->email();

        foreach ([
            '<p>No images at all.</p>',
            '<img src="https://example.test/logo.png">',
            '<img src="cid:already@plmail">',
            '<img src="data:image/svg+xml;base64,PHN2Zy8+">',
            '<img src="data:image/png;base64,">',
            '<p>data:image/png;base64,' . self::PNG . ' written out in a paragraph</p>',
        ] as $html) {
            self::assertSame($html, $this->embedder->embed($email, $html));
        }

        self::assertCount(0, $email->getAttachments());
    }

    /** A real logo is far past PCRE's backtrack limit if the pattern can backtrack. */
    public function testALargeImageIsEmbeddedRatherThanDroppedByThePattern(): void
    {
        $email = $this->email();
        $bytes = random_bytes(400_000);
        $html  = $this->embedder->embed($email, '<img src="data:image/jpeg;base64,' . base64_encode($bytes) . '">');

        self::assertMatchesRegularExpression('~^<img src="cid:inline-[0-9a-f]{16}\.jpg">$~', $html);
        self::assertSame($bytes, $email->getAttachments()[0]->getBody());
    }

    private function email(): Email
    {
        return new Email()->from('me@example.test')->to('you@example.test')->subject('Hello');
    }
}
