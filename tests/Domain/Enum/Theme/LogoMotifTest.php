<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Theme;

use App\Command\Branding\ExportLogoPaintsCommand;
use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The logo paint table the server draws with is the one the phone draws with.
 *
 * fixtures/logo-paints.json is the approved design, exported from the board's
 * own recipes, and the Android build generates its launcher icons from a copy of
 * it. The web draws the same icons from LogoMotif's recipes, ported to PHP. A
 * port that is one rounding rule or one role off is an icon a shade different on
 * the phone's home screen from the one in the topbar — invisible in review, and
 * unexplainable once somebody notices.
 *
 * So the whole table is compared, every motif × every paint × every part, and
 * through the export command rather than LogoMotif directly: the command is
 * what regenerates the phone's copy, so it is the thing that has to be right.
 */
final class LogoMotifTest extends TestCase
{
    public function testTheExportedTableIsTheApprovedDesign(): void
    {
        $tester = new CommandTester(new ExportLogoPaintsCommand());

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $fixture = file_get_contents(__DIR__ . '/fixtures/logo-paints.json');

        self::assertIsString($fixture);
        self::assertSame(
            json_decode($fixture, true, flags: JSON_THROW_ON_ERROR),
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
            'a recipe changed: regenerate the fixture and the Android copy from app:branding:export-paints, or put it back',
        );
    }

    /**
     * Bare on a dark top bar, an ink horn is no horn at all. The horn reads
     * the colourway's dark-chrome strokes, as the pl mark does, and a glyph
     * with a body of its own keeps its livery.
     */
    public function testADarkBarRepaintsOnlyWhatWouldVanishIntoIt(): void
    {
        $light = LogoMotif::BlueHorn->onChrome(LogoStyle::Ink);
        $dark = LogoMotif::BlueHorn->onChrome(LogoStyle::Ink, true);

        self::assertArrayNotHasKey('background', $light, 'bare means no tile');
        self::assertSame(LogoStyle::INK, $light['horn']);
        self::assertSame(LogoStyle::Ink->strokes(true)[0], $dark['horn']);

        self::assertSame(
            LogoMotif::LoveLetter->onChrome(LogoStyle::Ink),
            LogoMotif::LoveLetter->onChrome(LogoStyle::Ink, true),
            'the letter\'s white paper carries it on either chrome',
        );
    }
}
