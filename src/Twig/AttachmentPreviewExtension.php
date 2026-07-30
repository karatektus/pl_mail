<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Mail\MessagePart;
use App\Service\Mail\AttachmentThumbnailer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `attachment_has_preview(part)` — whether the chip should show a thumbnail.
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
        ];
    }

    public function hasPreview(MessagePart $part): bool
    {
        return $this->thumbnailer->isPreviewable($part);
    }
}
