<?php

declare(strict_types=1);

namespace App\Domain\Monitoring;

use App\Domain\Enum\Monitoring\ReportKind;
use App\Entity\Insight\InsightReport;
use App\Entity\Monitoring\CategoryReport;
use DateTimeImmutable;

/**
 * One report, of either kind, in the shape the panel and the export need.
 *
 * WHY A VIEW MODEL AND NOT A SHARED BASE ENTITY. The two tables have almost
 * nothing in common below the surface: a category report is four verdicts and
 * the evidence behind them, a missed-insight report is a snapshot of a mail
 * body somebody wants a parser written against. Making them inherit would put
 * a dozen columns on each row that are null for half of them, and would tie a
 * schema change on one feature to the other for ever.
 *
 * What they DO share is the handful of things a triage list is built from: when
 * it was reported, by whom, about which mail, and whether anybody has dealt
 * with it. That is this object. The entity travels along in {@see $source} for
 * the fold and the export, which are the only places the difference matters.
 *
 * {@see $problem} is the grouping key, and it is deliberately a plain string
 * rather than an enum: for a category report it is the disagreement itself
 * (`primary>promotions`), which is not a fixed set of anything — it is a pair
 * of categories, and the useful groups are whichever pairs this install has
 * actually produced.
 */
final readonly class ReportedMail
{
    public function __construct(
        public ReportKind $kind,
        public int $id,
        public DateTimeImmutable $reportedAt,
        public ?DateTimeImmutable $handledAt,
        public ?string $reportedBy,
        public ?string $fromName,
        public ?string $fromAddress,
        public ?string $subject,
        public string $problem,
        public InsightReport|CategoryReport $source,
    ) {
    }

    /**
     * How a selection names this row.
     *
     * Kind AND id, because the two tables number themselves independently and
     * `7` alone means two different reports. The export receives a list of
     * these and nothing else, so this is the only thing the browser has to know
     * about either table.
     */
    public function key(): string
    {
        return $this->kind->value . ':' . $this->id;
    }

    /** Still somebody's to do. */
    public function isPending(): bool
    {
        return null === $this->handledAt;
    }

    /**
     * The pasteable line — the product of the whole feature.
     *
     * Both kinds start with a date and a token naming the problem, so a copied
     * selection holding both stays readable without anybody having to work out
     * which fields belong to which shape.
     */
    public function asLine(): string
    {
        if (ReportKind::Category === $this->kind) {
            return $this->source->asLine();
        }

        return sprintf(
            '%s | no-insight | by:%s | from:%s <%s> | %s%s',
            $this->reportedAt->format('Y-m-d'),
            $this->reportedBy ?? '-',
            (string) $this->fromName,
            (string) $this->fromAddress,
            (string) $this->subject,
            // The reporter's own words, which InsightReport calls the single
            // most valuable column it has. Kept on the line for that reason,
            // and only when there are any.
            null !== $this->source->note && '' !== $this->source->note
                ? ' | note:' . $this->source->note
                : '',
        );
    }

    public static function fromInsight(InsightReport $report): self
    {
        return new self(
            ReportKind::Insight,
            (int) $report->id,
            $report->createdAt,
            $report->handledAt,
            $report->reportedBy?->email,
            $report->fromName,
            $report->fromAddress,
            $report->subject,
            // One group, because there is only one thing wrong: no insight came
            // out. What KIND of insight was missed is exactly the question the
            // export exists to answer, and it is not knowable from a row.
            'no-insight',
            $report,
        );
    }

    public static function fromCategory(CategoryReport $report): self
    {
        return new self(
            ReportKind::Category,
            (int) $report->id,
            $report->createdAt,
            $report->handledAt,
            $report->usr->email,
            $report->fromName,
            $report->fromAddress,
            $report->subject,
            $report->filed->value . '>' . $report->shouldBe->value,
            $report,
        );
    }
}
