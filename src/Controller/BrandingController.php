<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use App\Infrastructure\Routing\LogoPaintRequirement;
use App\Service\Appearance\LogoIcons;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;

/**
 * The logo, drawn three ways: as the icon tile, as the tab icon, and as the
 * top bar wears it. Beside them, the install manifest that names the tile as
 * the app's icon.
 *
 * plMail's logo has three hundred and thirty faces (Settings → Appearance →
 * Logo: ten icons, each in its own design or thirty-two colourways), and every
 * picture of it is one of these routes. Each draws exactly what its URL names —
 * `/{motif}/{paint}.svg` — and nothing else: no session, no user. The page that
 * links a picture knows the choice and spells it into the URL (see LogoIcons).
 *
 * The tab icon used to be the exception: one URL, drawn for whoever asked. That
 * broke the moment the choice could change live. The appearance pane points the
 * tab at the new choice the instant it is clicked, and the save that records it
 * lands a moment later. So the icon was drawn from the session's OLD choice,
 * and the browser kept that drawing under the new choice's URL for a week.
 * Every tab icon was the icon before, except for any choice the browser had
 * already fetched correctly (the one the page loaded with). Drawn from the URL,
 * there is nothing for the save to race.
 *
 * All four are public and, at the current drawing version, immutable. See
 * serve() for why that is safe.
 */
final class BrandingController extends AbstractController
{
    /**
     * A year: as long as a cache will keep anything. Safe only because the URL
     * changes when the drawing does — which is what the version check in
     * serve() makes sure of before promising it.
     */
    private const int IMMUTABLE = 31_536_000;

    /**
     * An hour, for a picture asked for without the current version. Short
     * enough that a deploy's new drawing reaches such a client the same day,
     * long enough that it is not a request per page; and every one after the
     * first is a 304 against the ETag.
     */
    private const int UNVERSIONED = 3_600;

    public function __construct(
        private readonly LogoIcons $icons,
        #[Autowire('%kernel.project_dir%')]
        private readonly string    $projectDir,
    ) {}

    /**
     * One icon tile: the glyph on its ground, as a phone's home screen shows
     * it. The settings pane draws its forty-two tiles with this, and a JMAP
     * client can too.
     *
     * Unknown motifs and paints 404 at the router: both requirements are read
     * off the enums, so a new colourway has an icon the day it exists.
     */
    #[Route(
        '/branding/icon/{motif}/{paint}.svg',
        name: 'app_branding_icon',
        requirements: ['motif' => new EnumRequirement(LogoMotif::class), 'paint' => new LogoPaintRequirement()],
        methods: ['GET'],
    )]
    public function icon(Request $request, LogoMotif $motif, string $paint): Response
    {
        $style = LogoStyle::tryFrom($paint);

        return $this->serve($request, 'icon.' . LogoIcons::choice($motif, $style), fn (): string => $this->tile($motif, $style));
    }

    /**
     * The tab icon.
     *
     * The pl mark sits bare in a tab strip, as it always has: seven strokes on
     * nothing is the most legible thing that fits in 16px. Every other icon is
     * its tile, because a horn with no ground behind it is a squiggle at that
     * size, and the tile is what the icon IS on a phone.
     */
    #[Route(
        '/branding/favicon/{motif}/{paint}.svg',
        name: 'app_branding_favicon',
        requirements: ['motif' => new EnumRequirement(LogoMotif::class), 'paint' => new LogoPaintRequirement()],
        methods: ['GET'],
    )]
    public function favicon(Request $request, LogoMotif $motif, string $paint): Response
    {
        $style = LogoStyle::tryFrom($paint);

        return $this->serve(
            $request,
            'favicon.' . LogoIcons::choice($motif, $style),
            fn (): string => LogoMotif::Pl === $motif ? $this->bare($motif, $style, false) : $this->tile($motif, $style),
        );
    }

    /**
     * The install manifest: public/manifest.webmanifest with the choice's tile
     * for its icon.
     *
     * Everything but the icons is read from that file, so the app's name, start
     * page and colours have one home, and the file stays what an installer that
     * never loaded a page would find.
     *
     * The icon is the one SVG tile and nothing beside it. The static file
     * listed three PNGs of the wax seal, and a launcher chooses among a
     * manifest's icons the way Firefox chooses a tab icon (see
     * _favicon.html.twig): a second candidate that is the same for everyone
     * wins for someone. An SVG has no size, so `any`.
     *
     * iOS does not read this for its home-screen icon; it wants a PNG
     * `apple-touch-icon`, which has no per-choice route (see app.html.twig).
     */
    #[Route(
        '/branding/manifest/{motif}/{paint}.webmanifest',
        name: 'app_branding_manifest',
        requirements: ['motif' => new EnumRequirement(LogoMotif::class), 'paint' => new LogoPaintRequirement()],
        methods: ['GET'],
    )]
    public function manifest(Request $request, LogoMotif $motif, string $paint): Response
    {
        $style = LogoStyle::tryFrom($paint);

        return $this->serve(
            $request,
            'manifest.' . LogoIcons::choice($motif, $style),
            fn (): string => $this->manifestJson($motif, $style),
            'application/manifest+json',
        );
    }

    /**
     * The logo as the app's own chrome wears it: the top bar's.
     *
     * The glyph bare, with no tile, on the 48 grid the pl mark is drawn on,
     * so every icon stands at the mark's scale. The @-horn is the exception
     * and keeps its tile (LogoMotif::needsGround()). `?dark=1` is the same
     * logo for a dark bar, which only changes the parts that would vanish
     * into one (LogoMotif::onChrome()). The page asks for both and lets the
     * theme show one, so a theme that follows the system is right either way.
     */
    #[Route(
        '/branding/logo/{motif}/{paint}.svg',
        name: 'app_branding_logo',
        requirements: ['motif' => new EnumRequirement(LogoMotif::class), 'paint' => new LogoPaintRequirement()],
        methods: ['GET'],
    )]
    public function logo(Request $request, LogoMotif $motif, string $paint): Response
    {
        $style = LogoStyle::tryFrom($paint);
        $dark = $request->query->getBoolean('dark');

        return $this->serve(
            $request,
            'logo.' . LogoIcons::choice($motif, $style) . ($dark ? '.dark' : ''),
            fn (): string => $motif->needsGround() ? $this->tile($motif, $style) : $this->bare($motif, $style, $dark),
        );
    }

    /**
     * One picture that depends on its URL and nothing else.
     *
     * Public, and outside the session firewall entirely (security.yaml gives
     * /branding/ `security: false`). A request that so much as read the
     * session would have Symfony rewrite this response to `private,
     * max-age=0`. That is the right default for a page, and forty-two
     * uncacheable images on the appearance pane.
     *
     * Immutable only for the URL LogoIcons hands out for this drawing.
     * Anything else (no version, or one from before a deploy) is served the
     * current drawing, but must not be told it can keep it forever: that URL
     * will not change when the drawing next does.
     *
     * @param \Closure(): string $draw
     */
    private function serve(Request $request, string $choice, \Closure $draw, string $type = 'image/svg+xml'): Response
    {
        $version = $this->icons->version();

        $response = new Response(headers: ['Content-Type' => $type]);
        $response->setPublic();
        $response->setEtag($choice . '.' . $version);

        if ($version === $request->query->get('v')) {
            $response->setMaxAge(self::IMMUTABLE);
            $response->setImmutable();
        } else {
            $response->setMaxAge(self::UNVERSIONED);
        }

        if ($response->isNotModified($request)) {
            return $response;
        }

        $response->setContent($draw());

        return $response;
    }

    private function manifestJson(LogoMotif $motif, ?LogoStyle $paint): string
    {
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode(
            (string) file_get_contents($this->projectDir . '/public/manifest.webmanifest'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $manifest['icons'] = [[
            'src'     => $this->icons->url($motif, $paint),
            'sizes'   => 'any',
            'type'    => 'image/svg+xml',
            'purpose' => 'any',
        ]];

        return json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** One tile: the glyph on its ground, clipped to the rounded square. */
    private function tile(LogoMotif $motif, ?LogoStyle $paint): string
    {
        [$parts, $glyphRamp, $tileRamp] = self::fill($motif->paints($paint));

        return $this->renderView('branding/icon.svg.twig', [
            'motif'      => $motif,
            'p'          => $parts,
            'glyph_ramp' => $glyphRamp,
            'tile_ramp'  => $tileRamp,
        ]);
    }

    /** One glyph with no tile, for light chrome or dark. */
    private function bare(LogoMotif $motif, ?LogoStyle $paint, bool $dark): string
    {
        [$parts, $glyphRamp] = self::fill($motif->onChrome($paint, $dark));

        return $this->renderView('branding/logo.svg.twig', [
            'motif'      => $motif,
            'p'          => $parts,
            'glyph_ramp' => $glyphRamp,
        ]);
    }

    /**
     * A motif's paints, turned into what the glyph templates read.
     *
     * The table form ({ramp: [...]}) is what the export and the phone share;
     * the templates want a paint they can drop into `fill`. A ramp becomes a
     * reference to the one gradient the picture defines for it. Every glyph
     * part that is a ramp is the same colourway's ramp, so one glyph gradient
     * and one ground gradient are all a picture ever needs.
     *
     * @param array<string, string|array{ramp: list<string>}|null> $paints
     *
     * @return array{array<string, string|null>, list<string>|null, list<string>|null}
     */
    private static function fill(array $paints): array
    {
        $parts = [];
        $glyphRamp = null;
        $tileRamp = null;

        foreach ($paints as $part => $value) {
            if (true === is_array($value)) {
                if ('background' === $part) {
                    $tileRamp = $value['ramp'];
                    $value = 'url(#t)';
                } else {
                    $glyphRamp = $value['ramp'];
                    $value = 'url(#g)';
                }
            }

            $parts[$part] = $value;
        }

        return [$parts, $glyphRamp, $tileRamp];
    }
}
