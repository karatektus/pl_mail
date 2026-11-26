<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\InlineAttachmentDetector;
use PHPUnit\Framework\TestCase;

/**
 * A part is inline only when the HTML body references its cid, whatever its
 * Content-Disposition says.
 *
 * The disposition used to decide when the body did not reference the part,
 * and Apple Mail sends every file it attaches as "inline" with no Content-ID.
 * Those files were stored as inline, and an inline part the body does not
 * embed is shown nowhere: no chip, no paperclip (#753). The Gmail case is the
 * older half of the same rule, a Content-ID on every uploaded attachment.
 *
 * A plain TestCase rather than the container: the detector has no
 * collaborators and reads nothing but its arguments.
 */
final class InlineAttachmentDetectorTest extends TestCase
{
    private InlineAttachmentDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new InlineAttachmentDetector();
    }

    public function testAPartTheBodyEmbedsIsInline(): void
    {
        self::assertTrue($this->detector->isInline('<logo@x>', '<img src="cid:logo@x">'));
    }

    public function testTheCidMatchIgnoresCaseAndSpace(): void
    {
        self::assertTrue($this->detector->isInline('Logo@X', '<img src="CID: logo@x">'));
    }

    /**
     * Gmail's web composer stamps a Content-ID on every attachment it uploads.
     */
    public function testAContentIdTheBodyNeverMentionsIsAnAttachment(): void
    {
        self::assertFalse($this->detector->isInline('<f_abc123>', '<p>See attached.</p>'));
    }

    /**
     * Apple Mail's shape: "Content-Disposition: inline", no Content-ID, a PDF
     * between the text parts. It was stored as inline and never shown.
     */
    public function testAnInlinePartWithoutAContentIdIsAnAttachment(): void
    {
        self::assertFalse($this->detector->isInline(null, '<p>See attached.</p>'));
        self::assertFalse($this->detector->isInline('', '<p>See attached.</p>'));
    }

    public function testWithoutAnHtmlBodyNothingIsInline(): void
    {
        self::assertFalse($this->detector->isInline('<logo@x>', null));
        self::assertFalse($this->detector->isInline('<logo@x>', ''));
    }
}
