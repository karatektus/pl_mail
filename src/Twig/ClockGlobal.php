<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Enum\User\ClockFormat;
use App\Entity\User\User;
use App\Service\User\ClockFormatResolver;
use App\Service\User\UserTimezoneResolver;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The `clock` global: which time format a template should print with.
 *
 * A global rather than a controller variable for the same reason
 * IntegrationsGlobal and CalendarGlobal are: the templates that print a time
 * are partials — a thread row, a chip, a topbar tooltip — included from layouts
 * that no single controller sits behind. Threading a format string through
 * every render that might reach one of them is how it ends up missing from the
 * one nobody remembered.
 *
 * Used as `{{ date|date(clock.time) }}`, `clock.timeCompact`, `clock.hour`.
 * Those three are the whole vocabulary; see ClockFormat for why there are
 * exactly three and not one per call site.
 *
 * Per-request memoised. The mail list alone reads this once per row, and a
 * resolver call per row is a settings-bag lookup fifty times for one answer.
 */
class ClockGlobal
{
    private ?ClockFormat $format = null;

    public function __construct(
        private readonly ClockFormatResolver  $clocks,
        private readonly Security             $security,
        private readonly UserTimezoneResolver $timezones,
    ) {
    }

    /** "9:30 am" or "09:30". */
    public function getTime(): string
    {
        return $this->format()->time();
    }

    /** "9:30" or "09:30" — where the context already says which half of the day. */
    public function getTimeCompact(): string
    {
        return $this->format()->timeCompact();
    }

    /** "9 am" or "09:00" — an axis label, always on the hour. */
    public function getHour(): string
    {
        return $this->format()->hour();
    }

    /** For a template that needs the choice itself rather than a format. */
    public function getIs12Hour(): bool
    {
        return ClockFormat::Twelve === $this->format();
    }

    /**
     * The IANA identifier every `|date` in this request is already being drawn
     * against — "Europe/Berlin", never an offset.
     *
     * Here rather than threaded through a controller for the same reason the
     * format is: it is wanted by a partial (the compose window's send-options
     * menu, which has to name tomorrow morning in the user's own clock) that
     * renders from three different actions and from a Turbo Stream besides.
     *
     * Note this is the *configured* zone, resolved exactly as
     * TwigTimezoneSubscriber resolves it — not anything read off the browser.
     * A laptop still on New York time does not move a Berlin user's morning.
     */
    public function getTimezone(): string
    {
        $user = $this->security->getUser();

        return $this->timezones->nameFor($user instanceof User ? $user : null);
    }

    private function format(): ClockFormat
    {
        if (null === $this->format) {
            $user = $this->security->getUser();

            $this->format = $this->clocks->resolve($user instanceof User ? $user : null);
        }

        return $this->format;
    }
}
