<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Calendar\CalendarSet;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Service\Calendar\CalendarSets;
use App\Service\Calendar\UserCalendars;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** What the calendar toolbar's sets menu draws. */
final class CalendarSetsExtension extends AbstractExtension
{
    public function __construct(
        private readonly CalendarSets  $sets,
        private readonly UserCalendars $calendars,
        private readonly Security      $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('calendar_sets_menu', $this->menu(...)),
        ];
    }

    /**
     * @return array{
     *     sets: list<CalendarSet>,
     *     active: ?CalendarSet,
     *     last: ?CalendarSet,
     *     sizes: array<string, int>,
     *     groups: array<string, list<Calendar>>,
     *     visible: list<Calendar>,
     *     total: int,
     * }
     */
    public function menu(): array
    {
        $user = $this->security->getUser();

        if (false === $user instanceof User) {
            return ['sets' => [], 'active' => null, 'last' => null, 'sizes' => [], 'groups' => [], 'visible' => [], 'total' => 0];
        }

        $calendars = $this->calendars->all($user);
        $sets      = $this->sets->all($user);
        $groups    = [];
        $sizes     = [];

        foreach ($sets as $set) {
            $sizes[$set->id] = $this->sets->size($set, $calendars);
        }

        foreach ($calendars as $calendar) {
            $groups[$this->source($calendar)][] = $calendar;
        }

        return [
            'sets'    => $sets,
            'active'  => $this->sets->active($user, $calendars),
            'last'    => $this->sets->last($user),
            'sizes'   => $sizes,
            'groups'  => $groups,
            'visible' => $this->calendars->visible($user),
            'total'   => count($calendars),
        ];
    }

    /** The account or connection a calendar syncs from; '' for plMail's own. */
    private function source(Calendar $calendar): string
    {
        if (false === $calendar->isSynced()) {
            return '';
        }

        return $calendar->integration->name ?? $calendar->account->displayAddress ?? '';
    }
}
