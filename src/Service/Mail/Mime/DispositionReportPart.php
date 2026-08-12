<?php

declare(strict_types=1);

namespace App\Service\Mail\Mime;

use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\AbstractMultipartPart;

/**
 * The `multipart/report` wrapper, with the report-type parameter that makes it
 * an MDN rather than a bounce.
 *
 * `report-type=disposition-notification` is not decoration. RFC 6522 defines
 * multipart/report as a container whose meaning comes entirely from that
 * parameter, and a receiving client reads it to decide whether the thing in
 * front of it is a delivery failure, a read receipt or an unknown attachment —
 * which is why a report sent without it renders as a broken bounce.
 *
 * Symfony ships MixedPart, AlternativePart, RelatedPart and DigestPart and no
 * way to add a parameter to any of them, so this is the fifth.
 */
final class DispositionReportPart extends AbstractMultipartPart
{
    public function getMediaSubtype(): string
    {
        return 'report';
    }

    public function getPreparedHeaders(): Headers
    {
        $headers = parent::getPreparedHeaders();

        $headers->setHeaderParameter('Content-Type', 'report-type', 'disposition-notification');

        return $headers;
    }
}
