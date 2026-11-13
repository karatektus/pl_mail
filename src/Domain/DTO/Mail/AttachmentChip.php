<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Domain\Helper\MediaAttachment;
use App\Domain\Helper\PdfAttachment;
use App\Entity\Mail\MessagePart;

/**
 * One file named under a list row, when the row shows attachments as chips
 * (#608).
 *
 * Not the MessagePart: a list page reads these for every row on it, and the
 * entity would hydrate every column of every part — storage paths, the crypto
 * fields — to draw a file name. ThreadRows reads the four columns the chip
 * uses instead, the way it reads the row's messages.
 *
 * `previewKind` is the reading pane's answer for the same file
 * (AttachmentPreviewExtension::previewKind()): a PDF, picture or video opens
 * the viewer from the list as it does from the message, anything else
 * downloads. Worked out here rather than by that Twig function, which takes
 * the entity this exists to avoid.
 */
final readonly class AttachmentChip
{
    public ?string $previewKind;

    public function __construct(
        public int $id,
        public ?string $filename,
        public ?string $contentType,
        bool $sealed = false,
    ) {
        $this->previewKind = match (true) {
            // Ciphertext: there is nothing in it a viewer could show.
            $sealed => null,
            PdfAttachment::matches($contentType, $filename) => 'pdf',
            default => MediaAttachment::kindOf($contentType, $filename),
        };
    }

    public static function of(MessagePart $part): self
    {
        return new self((int) $part->id, $part->filename, $part->contentType, $part->sealed);
    }

    /**
     * The Font Awesome file glyph for this kind of file, so a row of chips says
     * "a PDF and two pictures" before it is read. By type first and by
     * extension second: mail clients send `application/octet-stream` for
     * anything they do not recognise, and the name is then all there is.
     */
    public function icon(): string
    {
        $type      = strtolower((string) $this->contentType);
        $extension = strtolower(pathinfo((string) $this->filename, PATHINFO_EXTENSION));

        return match (true) {
            'pdf' === $this->previewKind => 'fa-file-pdf',
            MediaAttachment::IMAGE === $this->previewKind, str_starts_with($type, 'image/') => 'fa-file-image',
            MediaAttachment::VIDEO === $this->previewKind, str_starts_with($type, 'video/') => 'fa-file-video',
            str_starts_with($type, 'audio/') => 'fa-file-audio',
            in_array($extension, ['zip', 'rar', '7z', 'gz', 'tgz', 'tar', 'bz2', 'xz'], true) => 'fa-file-zipper',
            in_array($extension, ['doc', 'docx', 'odt', 'rtf', 'pages'], true) => 'fa-file-word',
            in_array($extension, ['xls', 'xlsx', 'ods', 'csv', 'numbers'], true) => 'fa-file-excel',
            in_array($extension, ['ppt', 'pptx', 'odp', 'key'], true) => 'fa-file-powerpoint',
            in_array($extension, ['ics', 'vcs'], true) => 'fa-calendar',
            str_starts_with($type, 'text/'), in_array($extension, ['txt', 'md', 'log'], true) => 'fa-file-lines',
            default => 'fa-file',
        };
    }
}
