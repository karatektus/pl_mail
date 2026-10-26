<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Helper\MediaAttachment;
use App\Domain\Helper\PdfAttachment;
use App\Entity\Mail\MessagePart;
use App\Service\Mail\AttachmentThumbnailer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `attachment_has_preview(part)` — whether the chip should show a thumbnail.
 * `attachment_preview_kind(part)` — which viewer, if any, a click opens.
 *
 * Answers from the part's own columns, so a thread with a dozen attachments
 * costs a dozen string comparisons and no I/O. The image is only decoded when
 * the browser actually asks for the thumbnail URL.
 */
final class AttachmentPreviewExtension extends AbstractExtension
{
    public function __construct(private readonly AttachmentThumbnailer $thumbnailer)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('attachment_has_preview', $this->hasPreview(...)),
            new TwigFunction('attachment_preview_kind', $this->previewKind(...)),
        ];
    }

    /**
     * Which viewer the chip should open this in: 'pdf', 'image', 'video', or
     * null for a plain download.
     *
     * Distinct from hasPreview(), which asks whether a THUMBNAIL can be drawn —
     * that is about raster images and GD. This asks whether there is a viewer
     * for it, and is decided from the declared type rather than from the
     * bytes. See App\Domain\Helper\PdfAttachment and MediaAttachment.
     */
    public function previewKind(MessagePart $part): ?string
    {
        // Ciphertext: there is nothing in it a viewer could show. See MessagePart::$sealed.
        if (true === $part->sealed) {
            return null;
        }

        if (true === PdfAttachment::matches($part->contentType, $part->filename)) {
            return 'pdf';
        }

        return MediaAttachment::kindOf($part->contentType, $part->filename);
    }

    public function hasPreview(MessagePart $part): bool
    {
        return $this->thumbnailer->isPreviewable($part);
    }
}
