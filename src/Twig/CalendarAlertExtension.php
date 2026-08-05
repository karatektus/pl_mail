<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Calendar\EventAlert;
use App\Domain\Enum\Calendar\AlertAction;
use App\Entity\Calendar\CalendarEvent;
use App\Service\Calendar\Alert\AlertReader;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `calendar_alert_choices(event)` — the alert checkboxes an event's editor
 * renders, each with its key, its label and whether it is on.
 *
 * A function rather than a controller variable, and that is deliberate. The
 * editor is opened by two actions (new and edit) and the same list has to be
 * resolved against the same rules by the save path; threading it through every
 * render array means the one that forgets shows an event with no alerts and
 * silently clears them on the next save. Asking here keeps the list and its keys
 * in one place — AlertReader — with this class doing nothing but putting words
 * on them.
 *
 * **The label is built here rather than on the enum or the DTO** because it is
 * the only part that needs a translator and a plural rule. "10 minutes before"
 * has to become "vor 10 Minuten" and "1 minute before" must not become
 * "1 Minuten", so the arbitrary offsets go through Symfony's plural syntax with
 * a %count%; the six common ones are fixed strings and get keys of their own,
 * which is what lets "At the time of the event" not be a degenerate "0 minutes
 * before".
 *
 * An alert whose action is not the default is labelled with it — "1 day before ·
 * Email" — because otherwise two rows in the list read identically and only one
 * of them sends mail.
 */
final class CalendarAlertExtension extends AbstractExtension
{
    /**
     * Labels for the offsets AlertReader offers as one click, keyed by the
     * offset itself.
     *
     * A table rather than arithmetic on the seconds: these six are fixed
     * phrases in every language this ships with, and deriving "an hour before"
     * from -3600 is how "1 hours before" gets shipped.
     *
     * @var array<string,string>
     */
    private const array COMMON_LABELS = [
        'PT0S'   => 'calendar.event.alert.at_time',
        '-PT5M'  => 'calendar.event.alert.minutes_5',
        '-PT10M' => 'calendar.event.alert.minutes_10',
        '-PT30M' => 'calendar.event.alert.minutes_30',
        '-PT1H'  => 'calendar.event.alert.hour_1',
        '-P1D'   => 'calendar.event.alert.day_1',
    ];

    public function __construct(
        private readonly AlertReader         $alerts,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('calendar_alert_choices', $this->choices(...)),
        ];
    }

    /**
     * @return list<array{key: string, label: string, checked: bool}>
     */
    public function choices(?CalendarEvent $event): array
    {
        $set = [];

        foreach (null === $event ? [] : $this->alerts->alertsOf($event) as $alert) {
            $set[$alert->key] = true;
        }

        $rows = [];

        foreach ($this->alerts->choicesFor($event) as $alert) {
            $rows[] = [
                'key'     => $alert->key,
                'label'   => $this->label($alert),
                'checked' => true === ($set[$alert->key] ?? false),
            ];
        }

        return $rows;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function label(EventAlert $alert): string
    {
        $label = $this->trigger($alert);

        // The default action is left unsaid. Writing "· Notification" on five of
        // the six common rows would make the one that says "· Email" harder to
        // spot, not easier.
        return AlertAction::Display === $alert->action
            ? $label
            : sprintf('%s · %s', $label, $this->translator->trans($alert->action->label()));
    }

    /**
     * When it goes off, in words.
     *
     * The fallbacks descend in how much is known: a common offset has a phrase,
     * any other offset is counted in the largest whole unit that divides it, an
     * absolute trigger is a date, and anything else — a trigger this build
     * cannot read — says so rather than rendering an empty row the user can
     * still untick.
     */
    private function trigger(EventAlert $alert): string
    {
        if (null !== $alert->absoluteAt) {
            return $this->translator->trans('calendar.event.alert.at_absolute', [
                '%when%' => $alert->absoluteAt->format('Y-m-d H:i'),
            ]);
        }

        $key = self::COMMON_LABELS[(string) $alert->offset] ?? null;

        if (null !== $key && false === $alert->relativeToEnd) {
            return $this->translator->trans($key);
        }

        $seconds = $alert->offsetSeconds;

        if (null === $seconds) {
            return $this->translator->trans('calendar.event.alert.unreadable');
        }

        return $this->translator->trans($this->relationOf($seconds, $alert->relativeToEnd), [
            '%amount%' => $this->amountOf($seconds),
        ]);
    }

    /**
     * How long, in the largest whole unit that divides it.
     *
     * Days first, then hours, then minutes — an alarm imported as -PT120M reads
     * as "2 hours", which is what the person who set it in another client meant.
     * An offset that is not a whole number of minutes rounds to minutes rather
     * than growing a fourth unit: a reminder at seven and a half minutes before
     * is not worth a phrase in three languages.
     *
     * Through the plural syntax, because "1 minutes" is the kind of thing that
     * ships and never gets fixed.
     */
    private function amountOf(int $seconds): string
    {
        $magnitude = abs($seconds);

        [$unit, $count] = match (true) {
            0 === $magnitude % 86400 => ['days', intdiv($magnitude, 86400)],
            0 === $magnitude % 3600  => ['hours', intdiv($magnitude, 3600)],
            default                  => ['minutes', (int) round($magnitude / 60)],
        };

        return $this->translator->trans(
            sprintf('calendar.event.alert.amount.%s', $unit),
            ['%count%' => $count],
        );
    }

    /** Which end of the event it is measured from, and on which side of it. */
    private function relationOf(int $seconds, bool $relativeToEnd): string
    {
        return match (true) {
            true === $relativeToEnd && 0 <= $seconds => 'calendar.event.alert.relation.after_end',
            true === $relativeToEnd                  => 'calendar.event.alert.relation.before_end',
            0 <= $seconds                            => 'calendar.event.alert.relation.after_start',
            default                                  => 'calendar.event.alert.relation.before_start',
        };
    }
}
