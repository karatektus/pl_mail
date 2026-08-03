<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Service\Calendar\RecurrenceRuleConverter;
use PHPUnit\Framework\TestCase;

/**
 * The event editor's repeat dropdown, and the contract between the two ends of
 * this class.
 *
 * The dropdown writes a JSCalendar rule and the expander reads an RRULE, and
 * nothing else checks that the first is a thing the second understands. A
 * frequency spelled in a way `toRrule()` drops would produce an event that
 * silently does not recur — which looks exactly like an event that was never
 * meant to.
 */
final class RecurrenceRuleConverterTest extends TestCase
{
    private RecurrenceRuleConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new RecurrenceRuleConverter();
    }

    /**
     * @return list<array{string,string}>
     */
    public static function repeatChoices(): array
    {
        return [
            ['daily', 'FREQ=DAILY'],
            ['weekly', 'FREQ=WEEKLY'],
            ['monthly', 'FREQ=MONTHLY'],
            ['yearly', 'FREQ=YEARLY'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('repeatChoices')]
    public function testEveryDropdownChoiceSurvivesTheTripToAnRrule(string $choice, string $expected): void
    {
        $rule = $this->converter->fromRepeatChoice($choice);

        self::assertIsArray($rule);
        self::assertSame($expected, $this->converter->toRrule($rule));
    }

    public function testTheRuleIsTaggedSoItRoundTripsAsJscalendar(): void
    {
        self::assertSame(
            ['@type' => 'RecurrenceRule', 'frequency' => 'weekly'],
            $this->converter->fromRepeatChoice('weekly'),
        );
    }

    /**
     * "Does not repeat" is the empty choice, and anything unrecognised means
     * the same thing. Guessing at a half-understood value would produce an
     * event on the wrong days, which is worse than one that does not recur.
     */
    public function testAnythingElseMeansItDoesNotRepeat(): void
    {
        self::assertNull($this->converter->fromRepeatChoice(''));
        self::assertNull($this->converter->fromRepeatChoice('never'));
        self::assertNull($this->converter->fromRepeatChoice('hourly'));
    }
}
