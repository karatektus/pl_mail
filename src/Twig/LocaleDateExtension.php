<?php

declare(strict_types=1);

namespace App\Twig;

use IntlDateFormatter;
use IntlDatePatternGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `|intl_date`: a date rendered in the reader's language.
 *
 * Twig's own `|date` takes a PHP format string, and PHP's date() has no idea
 * what language it is in — `date('D, j M')` is "Mon, 3 Aug" for everybody, in a
 * German UI as much as an English one. That is how a fully translated interface
 * ended up with English weekday headers over its calendar and "Aug 3, 13:04" on
 * every message.
 *
 * The argument here is an ICU SKELETON, not a pattern: it says which fields to
 * show, not how to arrange them. `'dMMM'` is "a day number and an abbreviated
 * month", and the arrangement is the locale's business — "3 Aug" in English,
 * "3. Aug." in German. Passing a pattern instead would move the ordering
 * decision back into the template, which is the bug in a new spelling: German
 * writes the day first and English does not, and no single pattern is right for
 * both.
 *
 * Skeletons in use, shown as en / de:
 *   EEE      Mon        / Mo.          weekday, abbreviated
 *   MMM      Aug        / Aug          month, abbreviated
 *   d        3          / 3            day of month
 *   dMMM     Aug 3      / 3. Aug.
 *   dMMMy    Aug 3, 2026 / 3. Aug. 2026
 *   EEEdMMM  Mon, Aug 3 / Mo., 3. Aug.
 *   yMMMM    August 2026 / August 2026
 *
 * Note the field ORDER flips between the two in every skeleton that carries
 * both a day and a month. That flip is the whole point.
 *
 * Not the time of day. That is the user's 12/24-hour preference rather than a
 * property of their language — see ClockGlobal — so times keep going through
 * `|date(clock.time)`, and a template that shows both composes the two.
 */
final class LocaleDateExtension extends AbstractExtension
{
    /**
     * Pattern generators are not cheap to build and there is one locale per
     * request, so this ends up holding exactly one entry in practice. Keyed all
     * the same, because a mail can carry a locale of its own.
     *
     * @var array<string, IntlDatePatternGenerator>
     */
    private array $generators = [];

    /** @var array<string, IntlDateFormatter> */
    private array $formatters = [];

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('intl_date', $this->format(...)),
        ];
    }

    /**
     * @param \DateTimeInterface|string|int|null   $date     anything Twig's own date filter accepts
     * @param \DateTimeZone|string|false|null      $timezone false keeps the date's own zone, as `|date` does
     */
    public function format(
        \DateTimeInterface|string|int|null $date,
        string                             $skeleton,
        \DateTimeZone|string|false|null    $timezone = null,
        ?string                            $locale = null,
    ): string {
        if (null === $date || '' === $date) {
            return '';
        }

        $date = $this->coerce($date);

        if (false !== $timezone && null !== $timezone) {
            $date = $date->setTimezone(
                $timezone instanceof \DateTimeZone ? $timezone : new \DateTimeZone($timezone),
            );
        }

        $locale ??= $this->locale();

        return $this->formatter($locale, $skeleton, $date->getTimezone())->format($date) ?: '';
    }

    private function formatter(string $locale, string $skeleton, \DateTimeZone $zone): IntlDateFormatter
    {
        $key = $locale . '|' . $skeleton . '|' . $zone->getName();

        if (isset($this->formatters[$key])) {
            return $this->formatters[$key];
        }

        $generator = $this->generators[$locale] ??= new IntlDatePatternGenerator($locale);

        return $this->formatters[$key] = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $zone,
            IntlDateFormatter::GREGORIAN,
            $generator->getBestPattern($skeleton),
        );
    }

    private function coerce(\DateTimeInterface|string|int $date): \DateTimeInterface
    {
        if ($date instanceof \DateTimeInterface) {
            return $date;
        }

        if (is_int($date)) {
            return (new \DateTimeImmutable())->setTimestamp($date);
        }

        // 'now' and friends, the same strings Twig's date filter takes.
        return new \DateTimeImmutable($date);
    }

    /**
     * en_PI has no ICU data and never will — it is a joke locale. ICU falls
     * back to `en` for it on its own, which is exactly the intent: the pirate
     * catalogue translates words, not date conventions.
     */
    private function locale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';
    }
}
