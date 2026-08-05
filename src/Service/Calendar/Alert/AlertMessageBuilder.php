<?php

declare(strict_types=1);

namespace App\Service\Calendar\Alert;

use App\Domain\DTO\Calendar\DueAlert;
use App\Service\Calendar\CalendarTimeResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What an alert says, independent of how it travels.
 *
 * One class because the two channels must not word the same reminder
 * differently — a push that says "10:00" and a mail that says "09:00" is a bug
 * report nobody can reproduce, and it is exactly what happens when each side
 * picks its own zone. Here the zone is asked for once, from the same resolver
 * the editor and every calendar view use.
 *
 * Deliberately not a template. A notification body is one line and an alert mail
 * is three; rendering them through Twig would put the wording somewhere the
 * push path cannot reach without a response, and the push path is the one that
 * matters most.
 *
 * The times shown are the OCCURRENCE's, which is the whole reason DueAlert
 * carries them rather than the event: a standup dragged into the afternoon has
 * to be announced for the afternoon.
 */
final readonly class AlertMessageBuilder
{
    public function __construct(
        private CalendarTimeResolver $time,
        private TranslatorInterface  $translator,
    ) {
    }

    /** The event's name, or a stand-in — a notification with no title is a blank box. */
    public function title(DueAlert $due): string
    {
        $title = trim((string) $due->event->title);

        return '' === $title ? $this->translator->trans('calendar.alert.untitled') : $title;
    }

    /**
     * When it is, in the zone the event is kept in.
     *
     * An all-day event gets no clock time. Rendering one would print a midnight
     * nobody chose and that is not stored either — an all-day event is floating,
     * so its "00:00" is an artefact of the column rather than a time.
     */
    public function when(DueAlert $due): string
    {
        $zone  = $this->time->eventZone($due->event, $due->user);
        $local = $due->startsAt->setTimezone($zone);

        return true === $due->event->isAllDay
            ? $local->format('D, j M Y')
            : $local->format('D, j M Y H:i T');
    }

    /** The notification body and the first line of the mail: when, and where. */
    public function body(DueAlert $due): string
    {
        $location = trim((string) $due->event->location);

        return '' === $location
            ? $this->when($due)
            : sprintf('%s · %s', $this->when($due), $location);
    }

    public function subject(DueAlert $due): string
    {
        return $this->translator->trans('calendar.alert.mail.subject', [
            '%title%' => $this->title($due),
        ]);
    }
}
