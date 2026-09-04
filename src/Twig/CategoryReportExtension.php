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
 * Grouped by the PROBLEM here rather than handed over as entities, because
 * that is the unit somebody acts on. Twenty reports are rarely twenty problems:
 * they are "shop mail keeps landing in Primary" four times and "a person keeps
 * landing in Promotions" once, and the fix for one is not the fix for the other.
 * Grouping by `filed → should` puts each of those together and lets whoever is
 * changing a rule take the group that is about their rule and leave the rest.
 *
 * The line itself is still built on the entity — it is the PRODUCT, and a format
 * assembled here would be a format that changes with the panel.
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

    /**
     * @return array{
     *     count: int,
     *     lines: list<string>,
     *     groups: list<array{
     *         key: string,
     *         filed: string,
     *         shouldBe: string,
     *         count: int,
     *         rows: list<array{id: int, line: string, date: string, from: string, subject: string}>,
     *     }>,
     * }
     */
    private function reports(): array
    {
        $rows   = $this->reports->recent();
        $groups = [];

        foreach ($rows as $report) {
            $key = $report->filed->value . '>' . $report->shouldBe->value;

            if (false === isset($groups[$key])) {
                $groups[$key] = [
                    'key'      => $key,
                    'filed'    => $report->filed->value,
                    'shouldBe' => $report->shouldBe->value,
                    'count'    => 0,
                    'rows'     => [],
                ];
            }

            ++$groups[$key]['count'];

            $groups[$key]['rows'][] = [
                'id'      => (int) $report->id,
                'line'    => $report->asLine(),
                'date'    => $report->createdAt->format('Y-m-d'),
                // One string: the table has a column for "who", and a name
                // without its address is not enough to write a rule against
                // while an address without its name is unreadable.
                'from'    => '' === $report->fromName
                    ? $report->fromAddress
                    : $report->fromName . ' <' . $report->fromAddress . '>',
                'subject' => $report->subject,
            ];
        }

        // Biggest problem first. A group of six is a rule worth changing; a
        // group of one is somebody's odd mail, and it belongs below.
        usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'count'  => count($rows),
            'lines'  => array_map(static fn (CategoryReport $r): string => $r->asLine(), $rows),
            'groups' => $groups,
        ];
    }
}
