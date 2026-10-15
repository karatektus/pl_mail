<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Proposal;

use App\Service\Calendar\Proposal\DateShapeGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The gate in front of the parser lets through what the parser can read.
 *
 * The failure this guards against is silent: a form the parser learns and the
 * gate does not is a date nobody is ever offered, because the mail never
 * reaches the parser. So every language the parser reads gets a row here.
 */
final class DateShapeGateTest extends TestCase
{
    #[DataProvider('dateShaped')]
    public function testWhatTheParserReadsGetsThrough(string $text): void
    {
        self::assertTrue((new DateShapeGate())->passes($text));
    }

    /** @return array<string, array{string}> */
    public static function dateShaped(): array
    {
        return [
            'German'               => ['Samstag um 15 Uhr bei mir?'],
            'English'              => ['can you do tomorrow at 9?'],
            'French month'         => ['Rendez-vous le 4 août'],
            'French month, accent' => ['le 12 février'],
            'French weekday'       => ['Rendez-vous mercredi ?'],
            'French hour'          => ['On dit 14h30 ?'],
            'demain'               => ['Demain, ça vous va ?'],
            'aujourd’hui'          => ['Aujourd’hui si possible'],
        ];
    }

    public function testProseWithoutADateDoesNot(): void
    {
        self::assertFalse((new DateShapeGate())->passes('Merci beaucoup, à bientôt.'));
    }
}
