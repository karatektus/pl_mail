<?php

declare(strict_types=1);

namespace App\Domain\Enum\Contacts;

/**
 * Which position the topbar's contacts button does not stop at: the
 * calendar's CalendarSwitchSkip, for the address book, offered the same way
 * under Settings → Contacts. One value rather than a switch per position, for
 * CalendarSwitchSkip's reason: skipping both would leave the button nowhere
 * to go, and one value cannot say that.
 */
enum ContactsSwitchSkip: string
{
    /** The three-way cycle. */
    case None = 'none';

    /** Mail ↔ the address book full width. */
    case Split = 'split';

    /** Mail ↔ the address book beside the mail. */
    case Contacts = 'contacts';
}
