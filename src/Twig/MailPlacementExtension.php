<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Mail\BinView;
use App\Service\Mail\MailPlacement;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `mail_placement(thread)` for the templates that decide which controls to
 * draw.
 *
 * A pass-through to the service on purpose. The purge route refuses anything
 * MailPlacement does not call discarded, so the templates and the controller
 * have to be answering the same question — and the way to guarantee that is for
 * there to be one answer rather than two implementations that agree today.
 *
 * `bin_view()` is the side of a partly deleted conversation the page shows,
 * which the placement of such a thread depends on; see BinView.
 */
final class MailPlacementExtension extends AbstractExtension
{
    public function __construct(
        private readonly MailPlacement $placement,
        private readonly BinView $bin,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mail_placement', $this->placement->of(...)),
            new TwigFunction('bin_view', $this->bin->isOpen(...)),
        ];
    }
}
