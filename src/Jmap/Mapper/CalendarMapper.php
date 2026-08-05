<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Entity\Calendar\Calendar;

/**
 * Maps a plMail Calendar onto the JMAP Calendar object this server publishes.
 *
 * The id is the `calendar` row id, and that is the whole of it — unlike a
 * Mailbox, whose id had to be a per-account binding because labels are
 * user-scoped and a JMAP account is one mail account. A Calendar is user-scoped
 * too, but it is served from exactly one account (see CalendarAccountResolver),
 * so there is one id space and nothing to translate between. **The same id is
 * what `CalendarEvent/query`'s `inCalendar` consumes**, which is the property
 * that matters: an id a client reads off a Calendar must select that calendar's
 * events when it hands it back, and here it does so without passing through a
 * second table.
 *
 * Writability is published as `myRights` rather than as an `isReadOnly` flag,
 * following RFC 8621's Mailbox. Two spellings of "may I write here?" is how one
 * of them ends up not being consulted, and the rights map is the one every JMAP
 * client already knows to look at.
 *
 * `isVisible` is published rather than acted on. It is the sidebar tick, a
 * display preference of the web UI, and a JMAP client filtering events by it
 * would hide from a phone what its user had chosen to hide in a browser — two
 * surfaces disagreeing about which events exist. So it is stated and the client
 * decides.
 */
final class CalendarMapper
{
    /**
     * @param list<string>|null $properties
     *
     * @return array<string,mixed>
     */
    public function toJmap(Calendar $calendar, ?array $properties = null): array
    {
        $full = $this->full($calendar);

        if (null === $properties) {
            return $full;
        }

        // "id" is always returned regardless of the requested property set.
        $filtered = ['id' => $full['id']];

        foreach ($properties as $property) {
            if (true === array_key_exists($property, $full)) {
                $filtered[$property] = $full[$property];
            }
        }

        return $filtered;
    }

    /**
     * @return array<string,mixed>
     */
    private function full(Calendar $calendar): array
    {
        return [
            'id' => (string) $calendar->id,
            'name' => $calendar->name,
            // #rrggbb, not the Tailwind token Mailbox.color carries. The two
            // are different vocabularies in the database — Label::$color is a
            // token, Calendar::$color is hex — and translating one into the
            // other here would invent a colour the user never picked.
            'color' => $calendar->color,
            'sortOrder' => $calendar->sortOrder,
            'isVisible' => $calendar->isVisible,
            'isDefault' => $calendar->isDefault,
            'timeZone' => $calendar->timeZone,
            // plMail extension: what the calendar is FOR, which is what decides
            // where an extracted event lands. A client cannot derive it and a
            // user cannot change it, but "the calendar this account's mail files
            // into" is worth being able to label.
            'role' => $calendar->role->value,
            // plMail extension: whether there is a remote behind this at all.
            // Distinct from the rights below — a calendar can mirror a remote
            // and still accept writes — and it is what tells a client that an
            // event it creates here will leave the machine.
            'isSynced' => $calendar->isSynced(),
            'myRights' => $this->rights($calendar),
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function rights(Calendar $calendar): array
    {
        $writable = false === $calendar->isReadOnly;

        return [
            'mayReadItems' => true,
            // Every write goes through the same gate: a mirror of somewhere
            // that does not accept writes back refuses all three, so a client
            // can grey the button out rather than discover it by SetError.
            'mayAddItems' => $writable,
            'mayUpdateAll' => $writable,
            'mayRemoveItems' => $writable,
            // The calendar itself, not its events. The two provisioned roles
            // are re-created rather than mourned, so deleting one is refused
            // (CalendarRole::isDeletable) — and Calendar/set is not implemented
            // at all, so this is advisory for now.
            'mayDelete' => $writable && true === $calendar->role->isDeletable(),
        ];
    }
}
