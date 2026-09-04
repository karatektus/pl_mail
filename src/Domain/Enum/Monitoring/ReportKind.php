<?php

declare(strict_types=1);

namespace App\Domain\Enum\Monitoring;

/**
 * The two things somebody can tell plMail it got wrong about a mail.
 *
 * They arrived a year apart, as two features with two tables, two panels and
 * two export buttons — and to the person pressing either one they are the same
 * sentence: "this mail was handled badly, here is what should have happened."
 * An admin sitting down to work through the pile does not care which table a
 * row is in; they care which mails are still waiting.
 *
 * So this enum is the seam. The tables stay apart, because the evidence really
 * is different — a wrong tab is a disagreement between three classifiers, a
 * missed insight is a mail body a parser could not read — but everything above
 * them (the list, the triage, the export) is written against this.
 */
enum ReportKind: string
{
    /** A mail an extractor should have understood and did not. */
    case Insight = 'insight';

    /** A mail somebody found in the wrong tab. */
    case Category = 'category';

    /** The translation key for what this kind is called on screen. */
    public function labelKey(): string
    {
        return 'admin.insight_reports.kind.' . $this->value;
    }
}
