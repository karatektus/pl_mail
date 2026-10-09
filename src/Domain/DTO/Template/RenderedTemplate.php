<?php

declare(strict_types=1);

namespace App\Domain\DTO\Template;

/**
 * A template with everything the server can know filled in, ready for the
 * composer to insert. Recipient variables are still open: markers in the HTML,
 * tokens in the subject — see TemplateRenderer.
 */
final readonly class RenderedTemplate
{
    public function __construct(
        public string $subject,
        public string $html,
    ) {
    }
}
