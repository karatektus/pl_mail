<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Monitoring\CategoryReport;
use App\Repository\Monitoring\CategoryReportRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `category_reports()` — mail somebody said was filed in the wrong tab.
 *
 * A function rather than a controller variable, for EmbeddingPresetExtension's
 * reason: admin/ai/_frame.html.twig is rendered from three actions — the panel,
 * the save and the connection test — and `strict_variables` is on, so a
 * variable passed by two of the three is a 500 on the third. That third is the
 * connection test, which is exactly when somebody is fiddling with the model
 * and most likely to want to see what it has been getting wrong.
 *
 * Flattened to lines here rather than handed over as entities, because the
 * PRODUCT of this feature is text somebody pastes somewhere else. The template
 * renders what it is given and owns no format.
 */
final class CategoryReportExtension extends AbstractExtension
{
    public function __construct(private readonly CategoryReportRepository $reports)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('category_reports', $this->reports(...)),
        ];
    }

    /** @return array{lines: list<string>, count: int} */
    private function reports(): array
    {
        $rows = $this->reports->recent();

        return [
            'lines' => array_map(static fn (CategoryReport $r): string => $r->asLine(), $rows),
            'count' => count($rows),
        ];
    }
}
