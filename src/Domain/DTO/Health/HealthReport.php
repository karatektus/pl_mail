<?php

declare(strict_types=1);

namespace App\Domain\DTO\Health;

use App\Domain\Enum\Health\HealthSeverity;

/**
 * Everything wrong with one user's accounts, at one moment.
 *
 * Sorted worst-first with consequences pulled in under their cause, so the
 * template can render the list in order and needs no opinion of its own about
 * what matters more.
 */
final readonly class HealthReport
{
    /** @param list<HealthIssue> $issues */
    private function __construct(
        public array $issues,
    ) {
    }

    /**
     * @param list<HealthIssue> $issues in any order
     */
    public static function of(array $issues): self
    {
        return new self(self::arrange($issues));
    }

    public static function healthy(): self
    {
        return new self([]);
    }

    public function isHealthy(): bool
    {
        return [] === $this->issues;
    }

    /** The worst severity present, or null when there is nothing wrong. */
    public function worstSeverity(): ?HealthSeverity
    {
        $worst = null;

        foreach ($this->issues as $issue) {
            if (null === $worst || $issue->severity->rank() < $worst->rank()) {
                $worst = $issue->severity;
            }
        }

        return $worst;
    }

    /**
     * What the topbar counts.
     *
     * Root causes only, and only those worth interrupting for. The install this
     * was built from would otherwise have shown "9" for what is, to the person
     * looking at it, one broken Google account — and a number that overstates
     * the problem is the fastest way to teach somebody the badge is noise.
     */
    public function indicatorCount(): int
    {
        $count = 0;

        foreach ($this->issues as $issue) {
            if (true === $issue->isConsequence()) {
                continue;
            }

            if (false === $issue->severity->warrantsIndicator()) {
                continue;
            }

            ++$count;
        }

        return $count;
    }

    /** The tone the indicator takes, counting only what it counts above. */
    public function indicatorTone(): ?string
    {
        $worst = null;

        foreach ($this->issues as $issue) {
            if (true === $issue->isConsequence() || false === $issue->severity->warrantsIndicator()) {
                continue;
            }

            if (null === $worst || $issue->severity->rank() < $worst->rank()) {
                $worst = $issue->severity;
            }
        }

        return $worst?->tone();
    }

    /**
     * Worst first, with each cause immediately followed by the issues it
     * explains.
     *
     * Consequences keep their cause's position rather than sorting on their own
     * severity: a failing calendar listed three cards below the dead grant that
     * caused it reads as a separate problem, which is exactly the confusion the
     * causedBy link exists to prevent.
     *
     * @param  list<HealthIssue> $issues
     * @return list<HealthIssue>
     */
    private static function arrange(array $issues): array
    {
        $roots         = [];
        $consequences  = [];

        foreach ($issues as $issue) {
            if (true === $issue->isConsequence()) {
                $consequences[(string) $issue->causedBy][] = $issue;

                continue;
            }

            $roots[] = $issue;
        }

        // usort is not stable across equal ranks in a way worth relying on, so
        // the comparator falls back to the id — a fixed order beats an
        // arbitrary one when a test has to assert on it.
        usort($roots, static function (HealthIssue $a, HealthIssue $b): int {
            return [$a->severity->rank(), $a->id] <=> [$b->severity->rank(), $b->id];
        });

        $arranged = [];

        foreach ($roots as $root) {
            $arranged[] = $root;

            foreach ($consequences[$root->id] ?? [] as $child) {
                $arranged[] = $child;
            }

            unset($consequences[$root->id]);
        }

        // A consequence whose cause is not in the report is not a consequence
        // any more — the cause was fixed, or was never this user's to see. It
        // still has to be shown, or it vanishes with nothing having fixed it.
        foreach ($consequences as $orphans) {
            foreach ($orphans as $orphan) {
                $arranged[] = $orphan;
            }
        }

        return $arranged;
    }
}
