<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\Helper\EventColor;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEvent\CalendarEventColorRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * `calendar_event_color(event, posted)` — everything the editor's colour
 * picker draws (#616): the value the form opens with, the swatches, and the
 * named colours its search looks through.
 *
 * A function rather than a controller variable for the reason
 * CalendarAlertExtension gives: the editor is rendered by the new, the edit and
 * the failed-save actions, and the one that forgot the variable would open an
 * event's editor on "Calendar colour" — and the next save would clear a colour
 * set on the phone.
 *
 * "Often used" is the user's own events counted (CalendarEventColorRepository),
 * limited to names: a hex value from some client is kept on its event and drawn
 * there, but it is not something the picker can hand to another event, since
 * COLOR on the wire only takes names.
 */
final class CalendarColorExtension extends AbstractExtension
{
    /** How many "Often used" swatches the popover has room for in one row. */
    private const int OFTEN_USED = 6;

    public function __construct(
        private readonly CalendarEventColorRepository $colors,
        private readonly Security                     $security,
        private readonly TranslatorInterface          $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('calendar_event_color', $this->picker(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            // Ink or white on top of a colour; see EventColor::onColor().
            new TwigFilter('calendar_on_color', EventColor::onColor(...)),
        ];
    }

    /**
     * @param string|null $posted what a failed save posted, which wins over the
     *                            stored value so the re-rendered editor shows
     *                            the colour the user picked rather than the old
     *                            one
     *
     * @return array{
     *     value: string,
     *     hex: string|null,
     *     label: string,
     *     custom: array{value: string, hex: string, label: string, ink: string}|null,
     *     often: list<array{value: string, hex: string, label: string, ink: string}>,
     *     grid: list<array{value: string, hex: string, label: string, ink: string}>,
     *     named: list<array{value: string, hex: string, label: string}>
     * }
     */
    public function picker(?CalendarEvent $event, ?string $posted = null): array
    {
        $stored = $event?->jscalendar['color'] ?? null;
        $value  = $posted ?? (true === is_string($stored) ? trim($stored) : '');
        $hex    = EventColor::hexOf($value);

        // A value the picker cannot offer — "#ff8800", or a CSS name outside the
        // forty — is still the event's colour. It gets a swatch of its own at
        // the front, so opening and saving the editor leaves it as it was.
        $custom = null !== $hex && false === in_array(EventColor::nameOf($value), EventColor::PICKER, true)
            ? $this->swatch($value, $hex)
            : null;

        return [
            // Posted back as it is, even when it cannot be drawn: a value the
            // save sees unchanged is kept verbatim (CalendarController::postedColor()).
            'value'  => $value,
            'hex'    => $hex,
            'label'  => null === $hex ? $this->translator->trans('calendar.event.color.calendar') : $this->labelOf($value),
            'custom' => $custom,
            'often'  => $this->oftenUsed(),
            'grid'   => array_map(
                fn (string $name): array => $this->swatch($name, EventColor::NAMES[$name]),
                array_slice(EventColor::PICKER, 0, EventColor::GRID_SIZE),
            ),
            'named'  => array_map(
                fn (string $name): array => [
                    'value' => $name,
                    'hex'   => EventColor::NAMES[$name],
                    'label' => $this->labelOf($name),
                ],
                EventColor::PICKER,
            ),
        ];
    }

    /**
     * @return list<array{value: string, hex: string, label: string, ink: string}>
     */
    private function oftenUsed(): array
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User || null === $user->id) {
            return [];
        }

        $often = [];

        // Asked for a few more than are shown, because hex values and unknown
        // names are counted by the query and dropped here.
        foreach ($this->colors->mostUsed($user->id, self::OFTEN_USED * 2) as $value) {
            $name = EventColor::nameOf($value);

            if (null !== $name) {
                $often[] = $this->swatch($name, EventColor::NAMES[$name]);
            }

            if (self::OFTEN_USED === count($often)) {
                break;
            }
        }

        return $often;
    }

    /**
     * @return array{value: string, hex: string, label: string, ink: string}
     */
    private function swatch(string $value, string $hex): array
    {
        return [
            'value' => $value,
            'hex'   => $hex,
            'label' => $this->labelOf($value),
            'ink'   => EventColor::onColor($hex),
        ];
    }

    /**
     * A picker name's translated label; any other name as itself; a hex value
     * as "Custom".
     */
    private function labelOf(string $value): string
    {
        $name = EventColor::nameOf($value);

        if (null === $name) {
            return $this->translator->trans('calendar.event.color.custom');
        }

        $key = EventColor::label($name);

        return null === $key ? $name : $this->translator->trans($key);
    }
}
