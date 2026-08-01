<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Domain\Enum\Mail\LabelColor;
use App\Service\Gmail\GmailLabelColorMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Matching by hue instead of by a lookup table is only defensible if it
 * actually holds over Gmail's real palette, so the whole palette is in here.
 *
 * The named cases are the ones a table would have got right and arithmetic can
 * get wrong: reds either side of 0°, where circular distance matters, and the
 * near-neutrals, where a hue exists numerically but means nothing.
 */
final class GmailLabelColorMapperTest extends TestCase
{
    private GmailLabelColorMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new GmailLabelColorMapper();
    }

    /** Gmail's documented palette, verbatim. */
    private const array PALETTE = [
        '#000000', '#434343', '#666666', '#999999', '#cccccc', '#efefef', '#f3f3f3', '#ffffff',
        '#fb4c2f', '#ffad47', '#fad165', '#16a766', '#43d692', '#4a86e8', '#a479e2', '#f691b3',
        '#f6c5be', '#ffe6c7', '#fef1d1', '#b9e4d0', '#c6f3de', '#c9daf8', '#e4d7f5', '#fcdee8',
        '#efa093', '#ffd6a2', '#fce8b3', '#89d3b2', '#a0eac9', '#a4c2f4', '#d0bcf1', '#fbc8d9',
        '#e66550', '#ffbc6b', '#fcda83', '#44b984', '#68dfa9', '#6d9eeb', '#b694e8', '#f7a7c0',
        '#cc3a21', '#eaa041', '#f2c960', '#149e60', '#3dc789', '#3c78d8', '#8e63ce', '#e07798',
        '#ac2b16', '#cf8933', '#d5ae49', '#0b804b', '#2a9c68', '#285bac', '#653e9b', '#b65775',
        '#822111', '#a46a21', '#aa8831', '#076239', '#1a764d', '#1c4587', '#41236d', '#83334c',
        '#464646', '#e7e7e7', '#0d3472', '#b6cff5', '#0d3b44', '#98d7e4', '#3d188e', '#e3d7ff',
        '#711a36', '#fbd3e0', '#8a1c0a', '#f2b2a8', '#7a2e0b', '#ffc8af', '#7a4706', '#ffdeb5',
        '#594c05', '#fbe983', '#684e07', '#fdedc1', '#0b4f30', '#b3efd3', '#04502e', '#a2dcc1',
        '#c2c2c2', '#4986e7', '#2da2bb', '#b99aff', '#994a64', '#f691b2', '#ff7537', '#ffad46',
        '#662e37', '#ebdbde', '#cca6ac', '#094228', '#42d692', '#16a765',
    ];

    /**
     * The whole palette resolves. A null here would be a Gmail label rendering
     * with no colour at all, which is the failure this mapper exists to stop.
     */
    public function testEveryColourInGmailsPaletteResolves(): void
    {
        foreach (self::PALETTE as $hex) {
            self::assertNotNull(
                $this->mapper->toLabelColor($hex),
                sprintf('%s resolved to nothing', $hex),
            );
        }
    }

    #[DataProvider('recognisable')]
    public function testRecognisableColoursLandWhereAPersonWouldPutThem(
        string $hex,
        LabelColor $expected,
    ): void {
        self::assertSame($expected, $this->mapper->toLabelColor($hex), $hex);
    }

    /**
     * @return iterable<string, array{string, LabelColor}>
     */
    public static function recognisable(): iterable
    {
        yield 'gmail red'        => ['#fb4c2f', LabelColor::Red];
        yield 'dark red'         => ['#8a1c0a', LabelColor::Red];
        yield 'gmail orange'     => ['#ffad47', LabelColor::Orange];
        yield 'gmail yellow'     => ['#fad165', LabelColor::Amber];
        yield 'gmail green'      => ['#16a766', LabelColor::Green];
        yield 'gmail blue'       => ['#4a86e8', LabelColor::Blue];
        yield 'deep blue'        => ['#1c4587', LabelColor::Blue];
        yield 'gmail purple'     => ['#a479e2', LabelColor::Violet];
        yield 'deep purple'      => ['#41236d', LabelColor::Violet];
        yield 'gmail pink'       => ['#f691b3', LabelColor::Pink];
        yield 'cyan'             => ['#2da2bb', LabelColor::Teal];
    }

    /**
     * Saturation decides before hue is consulted. Every one of these has a hue
     * numerically, and none of them has one worth reading — roughly a third of
     * Gmail's palette is in this state.
     */
    #[DataProvider('neutrals')]
    public function testNeutralsBecomeGreyWhateverTheirHue(string $hex): void
    {
        self::assertSame(LabelColor::Gray, $this->mapper->toLabelColor($hex), $hex);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function neutrals(): iterable
    {
        yield 'black'      => ['#000000'];
        yield 'near black' => ['#434343'];
        yield 'mid grey'   => ['#666666'];
        yield 'light grey' => ['#cccccc'];
        yield 'off white'  => ['#efefef'];
        yield 'white'      => ['#ffffff'];
        yield 'warm grey'  => ['#c2c2c2'];
    }

    /** Red sits at 0°, so its neighbours wrap rather than run to the far end. */
    public function testHueDistanceWrapsAroundRed(): void
    {
        self::assertSame(LabelColor::Red, $this->mapper->toLabelColor('#ff0505'));
        self::assertSame(LabelColor::Red, $this->mapper->toLabelColor('#ff0530'));
    }

    #[DataProvider('unreadable')]
    public function testUnreadableValuesStayUncoloured(?string $hex): void
    {
        self::assertNull($this->mapper->toLabelColor($hex));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unreadable(): iterable
    {
        yield 'null'      => [null];
        yield 'empty'     => [''];
        yield 'not hex'   => ['rebeccapurple'];
        yield 'too short' => ['#ff'];
        yield 'garbage'   => ['#zzzzzz'];
    }

    public function testShorthandHexIsAccepted(): void
    {
        self::assertSame(LabelColor::Red, $this->mapper->toLabelColor('#f00'));
    }

    /**
     * Gmail rejects any colour outside its palette, so an outbound pair that is
     * merely close is an API error rather than a near miss.
     */
    public function testEveryOutboundPairIsInGmailsPalette(): void
    {
        foreach (LabelColor::cases() as $color) {
            $pair = $this->mapper->toGmailColor($color);

            self::assertNotNull($pair, sprintf('%s has no outbound pair', $color->value));
            self::assertContains($pair['backgroundColor'], self::PALETTE, $color->value . ' background');
            self::assertContains($pair['textColor'], self::PALETTE, $color->value . ' text');
        }
    }

    /** Gmail takes the pair or neither, so there is no half of this to send. */
    public function testNoColourSendsNoPair(): void
    {
        self::assertNull($this->mapper->toGmailColor(null));
    }

    /**
     * Out and back is the identity for all nine. That is what makes writing
     * outbound safe; inbound is still conditional, because it is emphatically
     * NOT the identity for the other eighty of Gmail's colours.
     */
    public function testTheNineColoursSurviveARoundTrip(): void
    {
        foreach (LabelColor::cases() as $color) {
            $pair = $this->mapper->toGmailColor($color);

            self::assertSame(
                $color,
                $this->mapper->toLabelColor($pair['backgroundColor']),
                sprintf('%s did not survive a round trip', $color->value),
            );
        }
    }
}
