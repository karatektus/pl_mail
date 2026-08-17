<?php

declare(strict_types=1);

namespace App\Domain\Enum\Insight;

/**
 * What an extracted mail insight is about.
 *
 * The sibling of Calendar\ExtractionKind, and deliberately a separate enum
 * rather than more cases on it: that one classifies CALENDAR EVENTS — things
 * with an occurrence on a timeline — and doubles as the Happening Soon
 * filter. An insight is the other family: a fact read out of a mail that is
 * worth surfacing whether or not it carries a date. A parcel has an ETA some
 * days wide, a pull request has none at all; forcing either into an
 * occurrence row would put fake times on real things.
 */
enum InsightKind: string
{
    case Parcel = 'parcel';
    case Flight = 'flight';
    case Ticket = 'ticket';
    case GithubIssue = 'github-issue';
    case GithubPullRequest = 'github-pr';

    /** Font Awesome icon, so a card or a chip can render without a match arm. */
    public function icon(): string
    {
        return match ($this) {
            self::Parcel => 'fa-solid fa-box',
            self::Flight => 'fa-solid fa-plane',
            self::Ticket => 'fa-solid fa-ticket',
            self::GithubIssue => 'fa-solid fa-circle-dot',
            self::GithubPullRequest => 'fa-solid fa-code-pull-request',
        };
    }

    /** Translation key for the user-facing name. */
    public function transKey(): string
    {
        return 'insights.kind.' . $this->value;
    }
}
