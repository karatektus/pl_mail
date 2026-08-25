<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\PdfAttachment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a PDF worth offering a reader for.
 *
 * The interesting cases are all about careless senders: a type with parameters
 * on it, a type that predates the registered one, and Outlook's habit of
 * declaring everything `application/octet-stream`. The negatives matter as
 * much — believing a filename over a declared type is how a viewer gets handed
 * something it cannot parse.
 */
final class PdfAttachmentTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, bool}> */
    public static function attachments(): iterable
    {
        yield 'the registered type'        => ['application/pdf', 'contract.pdf', true];
        yield 'with a name parameter'      => ['application/pdf; name="contract.pdf"', 'contract.pdf', true];
        yield 'uppercase and padded'       => ['  APPLICATION/PDF  ', 'contract.pdf', true];
        yield 'the older x- type'          => ['application/x-pdf', 'contract.pdf', true];
        yield 'charset parameter'          => ['application/pdf;charset=binary', null, true];

        // Outlook declares this for almost everything it attaches; refusing it
        // would hide the reader on exactly the corporate mail that needs it.
        yield 'octet-stream named .pdf'    => ['application/octet-stream', 'contract.pdf', true];
        yield 'octet-stream, mixed case'   => ['application/octet-stream', 'Contract.PDF', true];

        yield 'octet-stream named .txt'    => ['application/octet-stream', 'notes.txt', false];
        yield 'octet-stream, no name'      => ['application/octet-stream', null, false];

        // The name never overrides a type that says otherwise. A part calling
        // itself a png IS a png, whatever it is called.
        yield 'png named .pdf'             => ['image/png', 'invoice.pdf', false];
        yield 'plain text named .pdf'      => ['text/plain', 'invoice.pdf', false];

        yield 'nothing at all'             => [null, null, false];
        yield 'no type, .pdf name'         => [null, 'invoice.pdf', false];
    }

    #[DataProvider('attachments')]
    public function testItRecognisesOnlyWhatItCanOpen(?string $type, ?string $filename, bool $expected): void
    {
        self::assertSame($expected, PdfAttachment::matches($type, $filename));
    }
}
