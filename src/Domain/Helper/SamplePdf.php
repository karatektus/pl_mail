<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * A small, valid PDF, built rather than committed.
 *
 * plMail ships two mailboxes of invented mail — the demo's, and the E2E
 * fixtures — and both wanted a PDF in them: the demo so a visitor has something
 * to open, the fixtures so the reader can be tested. A generated document beats
 * a binary blob in git for the reason a fixture usually should be code: it can
 * say what is unusual about it, and a blob cannot.
 *
 * Deliberately hand-written rather than produced by a library. The server has
 * no PDF library and is not gaining one to make sample data — see
 * AttachmentThumbnailer, which declines to render PDFs for the same reason.
 * What is needed here is a few hundred bytes of well-formed syntax, and that is
 * cheaper to write than to depend on.
 *
 * The cross-reference offsets are COMPUTED. A heredoc with hand-counted byte
 * offsets is correct exactly until somebody edits a string inside it, and the
 * failure then is a document that opens in some readers and not others.
 */
final readonly class SamplePdf
{
    /**
     * A document of plain lines, one page.
     *
     * @param list<string> $lines ASCII only — see escape()
     */
    public static function document(string $title, array $lines): string
    {
        $content = self::text($title, $lines);

        return self::assemble([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            $content,
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]);
    }

    /**
     * Two pages, the second of which is awkward on purpose.
     *
     * Page two carries `/Rotate 90` AND a MediaBox whose origin is not (0,0) —
     * the two things that make a stamped signature land in the wrong place, and
     * the two a PDF picked off the internet almost never has. A fixture that
     * exercises only the easy case passes while the feature is broken for the
     * documents people are actually asked to sign.
     */
    public static function awkward(): string
    {
        return self::assemble([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 7 0 R >> >> /Contents 4 0 R >>',
            self::text('plMail test page one', []),
            '<< /Type /Page /Parent 2 0 R /MediaBox [20 30 615 872] /Rotate 90 '
                .'/Resources << /Font << /F1 7 0 R >> >> /Contents 6 0 R >>',
            self::text('Rotated page two', []),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]);
    }

    /**
     * A content stream: a heading, then the lines under it.
     *
     * @param list<string> $lines
     */
    private static function text(string $heading, array $lines): string
    {
        $body = sprintf("BT /F1 18 Tf 72 760 Td (%s) Tj ET\n", self::escape($heading));
        $y    = 720;

        foreach ($lines as $line) {
            $body .= sprintf("BT /F1 11 Tf 72 %d Td (%s) Tj ET\n", $y, self::escape($line));
            $y -= 18;
        }

        return '<< /Length '.strlen($body)." >>\nstream\n".$body."endstream";
    }

    /**
     * PDF string literals escape their own delimiters.
     *
     * Non-ASCII is dropped rather than encoded: doing it properly means a font
     * with the right encoding, and this builder exists to avoid needing one.
     * Callers pass ASCII; a stray umlaut disappearing is better than a document
     * that will not parse.
     */
    private static function escape(string $text): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }

    /**
     * Objects in, a file out, with a correct cross-reference table.
     *
     * @param list<string> $objects 1-indexed in the document, 0-indexed here
     */
    private static function assemble(array $objects): string
    {
        $out     = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($out);
            $out .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $startxref = strlen($out);
        $count     = count($objects) + 1;

        $out .= "xref\n0 ".$count."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out."trailer\n<< /Size ".$count." /Root 1 0 R >>\nstartxref\n".$startxref."\n%%EOF\n";
    }
}
