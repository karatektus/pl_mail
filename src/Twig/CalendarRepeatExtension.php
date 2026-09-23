<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Calendar\CalendarEvent;
use App\Service\Calendar\RecurrenceRuleConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `calendar_repeat_is_custom(event)` — whether the editor's repeat dropdown
 * has to offer "keep the current rule" for this event.
 *
 * A function rather than a controller variable for the reason
 * CalendarAlertExtension gives: the editor is rendered by more than one action,
 * and the one that forgot the variable would select "does not repeat" for a
 * series the dropdown cannot show — and the next save would un-repeat it.
 */
final class CalendarRepeatExtension extends AbstractExtension
{
    public function __construct(
        private readonly RecurrenceRuleConverter $recurrence,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('calendar_repeat_is_custom', $this->isCustom(...)),
        ];
    }

    public function isCustom(?CalendarEvent $event): bool
    {
        return null !== $event && true === $this->recurrence->isBeyondTheDropdown($event->jscalendar);
    }
}
