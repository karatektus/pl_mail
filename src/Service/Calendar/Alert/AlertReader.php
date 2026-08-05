<?php

declare(strict_types=1);

namespace App\Service\Calendar\Alert;

use App\Domain\DTO\Calendar\EventAlert;
use App\Domain\Enum\Calendar\AlertAction;
use App\Entity\Calendar\CalendarEvent;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * The alerts on an event, read out of its canonical object and back into it.
 *
 * One class for both directions because there is one grammar. RFC 8984 §4.5.2
 * puts alerts in a map of Alert objects, each with an OffsetTrigger or an
 * AbsoluteTrigger and an action, and a reader that understood a shape the writer
 * did not produce would be a feature that works until the first save.
 *
 * **Keys are derived from the trigger and the action, not minted fresh.** The
 * key is what a delivery record names, so an alert whose key changed because
 * somebody fixed a typo in the title would be an alert that goes off a second
 * time. keyFor() is a pure function of "when and what", so the same reminder
 * arriving from Google today and from a CalDAV mirror tomorrow is one entry
 * rather than two — and a key that came in from elsewhere is kept exactly as it
 * arrived, because renaming it would have the same effect as minting a new one.
 *
 * **The editor addresses alerts by key rather than by value.** A checkbox posts
 * the key of an alert that already exists here or of one of the common offsets
 * below; anything else is dropped. That is what lets the six one-click choices
 * and an alarm imported from an .ics with an absolute trigger sit in the same
 * list without the form having to be able to *express* an absolute trigger — and
 * it means a crafted post cannot invent an alert, only tick one off a list this
 * class produced.
 *
 * Nothing here flushes, persists or decides when an alert fires. This is the
 * vocabulary; DueAlertReader is the schedule and AlertDeliverer is the delivery.
 */
final readonly class AlertReader
{
    /**
     * The offsets offered as one click, in the order they are offered.
     *
     * ISO 8601 signed durations, negative meaning "before the start" —
     * `PT0S` is "at the time". These are the six every calendar offers, and they
     * are here rather than in the template because the save path has to resolve
     * a posted key against exactly the same list the editor rendered.
     *
     * @var list<string>
     */
    public const array COMMON_OFFSETS = ['PT0S', '-PT5M', '-PT10M', '-PT30M', '-PT1H', '-P1D'];

    /**
     * How many alerts one event may carry.
     *
     * An .ics from a stranger is allowed to say it has forty thousand VALARMs,
     * and every one of them would be a row in the delivery table and a push
     * notification. Ten is more than anybody sets by hand and the excess is
     * logged rather than silently trimmed.
     */
    public const int MAX_ALERTS = 10;

    /**
     * The longest lead the editor's custom field accepts, in minutes — thirty-one
     * days, which is also DueAlertReader's own horizon. A larger value would be
     * stored, never fire, and look like a bug rather than a limit.
     */
    public const int MAX_CUSTOM_MINUTES = 44640;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Every alert stored on this event, in the order the map holds them.
     *
     * Entries that are not readable as an Alert are skipped rather than
     * refused: the map comes from storage and from three providers, and one
     * malformed entry must not cost an event the alerts beside it.
     *
     * @return list<EventAlert>
     */
    public function alertsOf(CalendarEvent $event): array
    {
        $alerts = $event->jscalendar['alerts'] ?? null;

        if (false === is_array($alerts)) {
            return [];
        }

        $read = [];

        foreach ($alerts as $key => $alert) {
            if (count($read) >= self::MAX_ALERTS) {
                $this->logger->info('Calendar: event carries more alerts than are honoured', [
                    'eventId' => $event->id,
                    'count'   => count($alerts),
                    'kept'    => self::MAX_ALERTS,
                ]);

                break;
            }

            if (false === is_array($alert)) {
                continue;
            }

            $parsed = $this->parse((string) $key, $alert);

            if (null !== $parsed) {
                $read[] = $parsed;
            }
        }

        return $read;
    }

    /**
     * The full list the editor renders: the six common offsets, then anything
     * already on this event that is not one of them.
     *
     * Common first and always present, so the six one-click choices sit in a
     * fixed order however the event was made. A stored alert that happens to BE
     * one of the six is not listed twice — keyFor() gives it the same key, which
     * is the point of deriving keys rather than minting them.
     *
     * @return list<EventAlert>
     */
    public function choicesFor(?CalendarEvent $event): array
    {
        $choices = [];

        foreach (self::COMMON_OFFSETS as $offset) {
            $alert = $this->offsetAlert($offset, AlertAction::Display);

            if (null !== $alert) {
                $choices[$alert->key] = $alert;
            }
        }

        foreach (null === $event ? [] : $this->alertsOf($event) as $stored) {
            $choices[$stored->key] ??= $stored;
        }

        return array_values($choices);
    }

    /**
     * The alerts a save means, resolved from what the editor posted.
     *
     * $keys are ticked checkboxes and are resolved against choicesFor() — the
     * exact list that was rendered — so an unknown key is dropped rather than
     * becoming an alert nobody asked for. The custom field is minted here
     * instead, because a value the user typed has no key until it has one.
     *
     * @param list<string> $keys
     *
     * @return list<EventAlert>
     */
    public function chosen(
        ?CalendarEvent $event,
        array          $keys,
        ?int           $customMinutes = null,
        ?AlertAction   $customAction = null,
    ): array {
        $available = [];

        foreach ($this->choicesFor($event) as $choice) {
            $available[$choice->key] = $choice;
        }

        $chosen = [];

        foreach ($keys as $key) {
            $alert = $available[$key] ?? null;

            if (null !== $alert) {
                $chosen[$alert->key] = $alert;
            }
        }

        $custom = $this->customAlert($customMinutes, $customAction ?? AlertAction::Display);

        if (null !== $custom) {
            $chosen[$custom->key] ??= $custom;
        }

        return array_slice(array_values($chosen), 0, self::MAX_ALERTS);
    }

    /**
     * A number of minutes before the start, as an alert.
     *
     * Null for zero, for a negative, and for anything past MAX_CUSTOM_MINUTES:
     * the field is a plain number input and all three arrive from a request.
     * Zero is the one worth naming — it is what an empty field posts, and
     * treating it as "at the time of the event" would give every save an alert
     * the user never asked for. The one-click list already has that choice.
     */
    public function customAlert(?int $minutes, AlertAction $action): ?EventAlert
    {
        if (null === $minutes || 0 >= $minutes || $minutes > self::MAX_CUSTOM_MINUTES) {
            return null;
        }

        return $this->offsetAlert($this->isoOffset(-$minutes * 60), $action);
    }

    /**
     * One offset alert, built the way every producer here builds one.
     *
     * Public because the sync mappers mint alerts from a provider's own
     * "fifteen minutes before" integer and must not each derive a key of their
     * own — see the class docblock.
     */
    public function offsetAlert(string $offset, AlertAction $action, bool $relativeToEnd = false): ?EventAlert
    {
        $seconds = $this->offsetSeconds($offset);

        if (null === $seconds) {
            return null;
        }

        return new EventAlert(
            key:           $this->keyFor($offset, $relativeToEnd, $action),
            action:        $action,
            offset:        $offset,
            offsetSeconds: $seconds,
            relativeToEnd: $relativeToEnd,
            absoluteAt:    null,
        );
    }

    /**
     * A list of alerts as the `alerts` map RFC 8984 stores.
     *
     * An empty list answers an empty array, and CalendarEventWriter drops the
     * key entirely rather than storing `{}` — an empty map is not a fact about
     * an event, and leaving one behind makes every event that ever had an alert
     * read as though it still does.
     *
     * @param list<EventAlert> $alerts
     *
     * @return array<string,array<string,mixed>>
     */
    public function toJsCalendar(array $alerts): array
    {
        $map = [];

        foreach ($alerts as $alert) {
            $map[$alert->key] = $alert->toJsCalendar();
        }

        return $map;
    }

    /**
     * Seconds, signed, for an ISO 8601 duration — or null when it is not one.
     *
     * Measured by adding the interval to the epoch rather than by reading the
     * DateInterval's own fields: `-P1W` sets $d to 7 and nothing else, and a
     * hand-rolled sum of the fields is a second implementation of a grammar that
     * already has one. JSCalendar's Duration admits no month or year designator
     * (RFC 8984 §1.4.6), so every unit it allows is a fixed number of seconds and
     * the epoch is as good a reference as any other instant.
     */
    public function offsetSeconds(string $offset): ?int
    {
        $offset    = trim($offset);
        $negative  = str_starts_with($offset, '-');
        $magnitude = true === $negative ? substr($offset, 1) : $offset;

        try {
            $interval = new DateInterval($magnitude);
        } catch (\Exception) {
            return null;
        }

        $epoch   = new DateTimeImmutable('@0');
        $seconds = $epoch->add($interval)->getTimestamp();

        return true === $negative ? -$seconds : $seconds;
    }

    /**
     * The ISO 8601 signed duration a number of seconds is written as.
     *
     * Deliberately not shared with CalendarEventWriter::isoDuration(), which
     * answers the same question for an event's length. That one is unsigned by
     * construction — a duration cannot be negative — and giving it a sign would
     * make an end-before-start bug expressible where it is currently clamped.
     */
    public function isoOffset(int $seconds): string
    {
        if (0 === $seconds) {
            return 'PT0S';
        }

        $sign      = 0 > $seconds ? '-' : '';
        $magnitude = abs($seconds);

        $days    = intdiv($magnitude, 86400);
        $hours   = intdiv($magnitude % 86400, 3600);
        $minutes = intdiv($magnitude % 3600, 60);
        $rest    = $magnitude % 60;

        $time = (0 < $hours ? $hours . 'H' : '')
            . (0 < $minutes ? $minutes . 'M' : '')
            . (0 < $rest ? $rest . 'S' : '');

        return $sign . 'P' . (0 < $days ? $days . 'D' : '') . ('' === $time ? '' : 'T' . $time);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The key an alert with this trigger and this action lives under.
     *
     * Readable on purpose: these end up in stored JSON that a person debugging a
     * sync will read, and an opaque hash there answers nothing. Uniqueness is
     * what matters and the three parts that decide when an alert fires are all
     * in it.
     */
    private function keyFor(?string $offset, bool $relativeToEnd, AlertAction $action, ?DateTimeImmutable $absoluteAt = null): string
    {
        if (null !== $absoluteAt) {
            return sprintf('%s/abs:%s', $action->value, $absoluteAt->format('Y-m-d\TH:i:s\Z'));
        }

        return sprintf(
            '%s/%s%s',
            $action->value,
            (string) $offset,
            true === $relativeToEnd ? ':end' : '',
        );
    }

    /**
     * One stored Alert object, or null when its trigger says nothing usable.
     *
     * The key that arrived is kept rather than re-derived. An event mirrored
     * from another client keys its alerts however that client chose to, and
     * renaming them here would make every delivery record point at an alert that
     * no longer exists — which is the same as never having recorded one.
     *
     * @param array<string,mixed> $alert
     */
    private function parse(string $key, array $alert): ?EventAlert
    {
        $trigger = $alert['trigger'] ?? null;

        if (false === is_array($trigger)) {
            return null;
        }

        $action = AlertAction::fromJsCalendar(
            true === is_string($alert['action'] ?? null) ? $alert['action'] : null,
        );

        $when = $trigger['when'] ?? null;

        if (true === is_string($when)) {
            $absoluteAt = $this->instant($when);

            if (null === $absoluteAt) {
                return null;
            }

            return new EventAlert(
                key:           '' === $key ? $this->keyFor(null, false, $action, $absoluteAt) : $key,
                action:        $action,
                offset:        null,
                offsetSeconds: null,
                relativeToEnd: false,
                absoluteAt:    $absoluteAt,
            );
        }

        $offset = $trigger['offset'] ?? null;

        if (false === is_string($offset)) {
            return null;
        }

        $seconds = $this->offsetSeconds($offset);

        if (null === $seconds) {
            return null;
        }

        $relativeToEnd = 'end' === ($trigger['relativeTo'] ?? 'start');

        return new EventAlert(
            key:           '' === $key ? $this->keyFor($offset, $relativeToEnd, $action) : $key,
            action:        $action,
            offset:        $offset,
            offsetSeconds: $seconds,
            relativeToEnd: $relativeToEnd,
            absoluteAt:    null,
        );
    }

    /** A UTCDateTime (RFC 8984 §1.4.5), or null when it is not one. */
    private function instant(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value)->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
