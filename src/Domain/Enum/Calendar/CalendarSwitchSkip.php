<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * Which position the topbar's calendar button does not stop at.
 *
 * The button cycles mail → split → calendar by default. Somebody who only ever
 * wants one of the two calendar positions can have the button go between the
 * mail and that one, one press each way — Settings → Calendar → Behaviour.
 * The skipped position is not removed: it is still a stored mode, and the drag
 * handle still reaches it, so a switch that skips it has to cope with being
 * found in it (see CalendarPaneMode::next()).
 *
 * One value rather than a switch per position, because skipping both would
 * leave the button with nowhere to go, and a single value cannot be in that
 * state. It is stored as it is, in User::$calendarSwitchSkip, and the settings
 * pane offers it as one choice of three.
 */
enum CalendarSwitchSkip: string
{
    /** The three-way cycle, what the button has always done. */
    case None = 'none';

    /** Mail ↔ the calendar full width. */
    case Split = 'split';

    /** Mail ↔ the calendar beside the mail. */
    case Calendar = 'calendar';
}
