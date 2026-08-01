<?php

declare(strict_types=1);

namespace App\Tests\Service\Graph;

use App\Domain\Enum\Mail\LabelColor;
use App\Service\Graph\GraphCategoryColorMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Outlook has twenty-five colours and plMail has nine, so this map is lossy by
 * construction. What it must not be is *wrong*: a category the user made red
 * has to come back red, and one Microsoft adds in future has to come back
 * uncoloured rather than as whatever the array happens to fall through to.
 *
 * The round-trip case is the one that justifies the design. Colour survives
 * out and back for the nine tokens plMail can express, and the shades it
 * cannot are deliberately not written back — which is why the syncer only
 * reads a colour onto a label that has none.
 */
final class GraphCategoryColorMapperTest extends TestCase
{
    private GraphCategoryColorMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new GraphCategoryColorMapper();
    }

    #[DataProvider('presets')]
    public function testEveryDocumentedPresetMapsToAColour(string $preset, LabelColor $expected): void
    {
        self::assertSame($expected, $this->mapper->toLabelColor($preset));
    }

    /**
     * @return iterable<string, array{string, LabelColor}>
     */
    public static function presets(): iterable
    {
        yield 'red'            => ['preset0', LabelColor::Red];
        yield 'orange'         => ['preset1', LabelColor::Orange];
        yield 'brown'          => ['preset2', LabelColor::Orange];
        yield 'yellow'         => ['preset3', LabelColor::Amber];
        yield 'green'          => ['preset4', LabelColor::Green];
        yield 'teal'           => ['preset5', LabelColor::Teal];
        yield 'olive'          => ['preset6', LabelColor::Green];
        yield 'blue'           => ['preset7', LabelColor::Blue];
        yield 'purple'         => ['preset8', LabelColor::Violet];
        yield 'cranberry'      => ['preset9', LabelColor::Pink];
        yield 'steel'          => ['preset10', LabelColor::Gray];
        yield 'black'          => ['preset14', LabelColor::Gray];
        yield 'dark red'       => ['preset15', LabelColor::Red];
        yield 'dark cranberry' => ['preset24', LabelColor::Pink];
    }

    /** All twenty-five, so a gap in the table is a failure rather than a null. */
    public function testEveryPresetInTheDocumentedRangeIsCovered(): void
    {
        for ($i = 0; $i <= 24; $i++) {
            self::assertNotNull(
                $this->mapper->toLabelColor('preset' . $i),
                sprintf('preset%d has no mapping', $i),
            );
        }
    }

    /**
     * Graph's own "no colour", an absent value, and anything Microsoft adds
     * after preset24 all mean the same thing here: leave it for the user.
     * Guessing at an unknown constant is how a label ends up a colour nobody
     * chose.
     */
    #[DataProvider('uncoloured')]
    public function testUnknownAndAbsentValuesStayUncoloured(?string $preset): void
    {
        self::assertNull($this->mapper->toLabelColor($preset));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function uncoloured(): iterable
    {
        yield 'none'      => ['none'];
        yield 'null'      => [null];
        yield 'empty'     => [''];
        yield 'future'    => ['preset25'];
        yield 'nonsense'  => ['chartreuse'];
    }

    /** Graph writes lower-case, but the docs render the constants capitalised. */
    public function testPresetLookupIsCaseInsensitive(): void
    {
        self::assertSame(LabelColor::Blue, $this->mapper->toLabelColor('Preset7'));
        self::assertSame(LabelColor::Blue, $this->mapper->toLabelColor('  preset7  '));
    }

    public function testEveryLabelColourHasAPresetToGoOutAs(): void
    {
        foreach (LabelColor::cases() as $color) {
            self::assertNotSame(
                GraphCategoryColorMapper::NO_COLOR,
                $this->mapper->toPreset($color),
                sprintf('%s has no outbound preset', $color->value),
            );
        }
    }

    public function testNoColourGoesOutAsNone(): void
    {
        self::assertSame(GraphCategoryColorMapper::NO_COLOR, $this->mapper->toPreset(null));
    }

    /**
     * The property that makes writing back safe for the nine we can express:
     * out and back is the identity. It is NOT the identity for Outlook's dark
     * shades, which is precisely why the syncer only ever reads a colour onto
     * a label that does not have one.
     */
    public function testTheNineColoursSurviveARoundTrip(): void
    {
        foreach (LabelColor::cases() as $color) {
            self::assertSame(
                $color,
                $this->mapper->toLabelColor($this->mapper->toPreset($color)),
                sprintf('%s did not survive a round trip', $color->value),
            );
        }
    }
}
