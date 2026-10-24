<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use App\Domain\Helper\AppIconStorage;
use App\Domain\Helper\CustomLogoStorage;
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
 * top bar wears it. Beside them, the installed app's: its manifest, and the
 * PNGs the user's browser drew for it (#141).
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
 * All of them are public and, at the current drawing version, immutable. See
 * serve() for why that is safe, and appIcon() for the one exception.
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
        private readonly LogoIcons         $icons,
        private readonly AppIconStorage    $appIcons,
        private readonly CustomLogoStorage $customLogos,
        #[Autowire('%kernel.project_dir%')]
        private readonly string            $projectDir,
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
     * The tile with its ground out to every edge, for what cuts its own
     * corners: iOS's home-screen icon and a manifest's maskable icon. The
     * browser draws this into the PNGs of appIcon(); see icon.svg.twig.
     */
    #[Route(
        '/branding/bleed/{motif}/{paint}.svg',
        name: 'app_branding_bleed',
        requirements: ['motif' => new EnumRequirement(LogoMotif::class), 'paint' => new LogoPaintRequirement()],
        methods: ['GET'],
    )]
    public function bleed(Request $request, LogoMotif $motif, string $paint): Response
    {
        $style = LogoStyle::tryFrom($paint);

        return $this->serve($request, 'bleed.' . LogoIcons::choice($motif, $style), fn (): string => $this->tile($motif, $style, true));
    }

    /**
     * The install manifest: public/manifest.webmanifest with the choice's icons.
     *
     * Everything but the icons is read from that file, so the app's name, start
     * page and colours have one home, and the file stays what an installer that
     * never loaded a page would find.
     *
     * `?u=` is the user's key (AppIconStorage): with it the icons are that
     * user's PNGs of this choice, without it (a page nobody is signed in to)
     * the file's own wax seal. Only the choice's icons and nothing else: a
     * launcher chooses among a manifest's icons the way Firefox chooses a tab
     * icon (see _favicon.html.twig), and a second candidate that is the same
     * for everyone wins for someone.
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
        $key   = (string) $request->query->get('u', '');

        if ('' !== $key && 1 !== preg_match('/^' . AppIconStorage::KEY_PATTERN . '$/', $key)) {
            throw $this->createNotFoundException();
        }

        return $this->serve(
            $request,
            'manifest.' . LogoIcons::choice($motif, $style) . ('' === $key ? '' : '.' . $key),
            fn (): string => $this->manifestJson($motif, $style, '' === $key ? null : $key),
            'application/manifest+json',
        );
    }

    /**
     * One of a user's installed-app PNGs, as their browser drew it.
     *
     * The one picture here that is not a drawing of its URL: it is a file the
     * user uploaded (AppIconStorage), and it may not be there yet, for a choice
     * picked before this existed or after a deploy that changed the drawing.
     * Then the wax seal's static PNG of the same shape stands in, so a manifest
     * never names an icon that 404s, and it is served `no-cache`: the user's own
     * arrives with the next page load, and must not be shadowed by the stand-in
     * for a year.
     */
    #[Route(
        '/branding/app-icon/{key}/{name}/{motif}/{paint}.png',
        name: 'app_branding_app_icon',
        requirements: [
            'key'   => AppIconStorage::KEY_PATTERN,
            'name'  => 'tile-192|tile-512|bleed-512|bleed-180',
            'motif' => new EnumRequirement(LogoMotif::class),
            'paint' => new LogoPaintRequirement(),
        ],
        methods: ['GET'],
    )]
    public function appIcon(Request $request, string $key, string $name, LogoMotif $motif, string $paint): Response
    {
        return $this->png($request, $key, $name, LogoIcons::choice($motif, LogoStyle::tryFrom($paint)));
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
     * The user's uploaded logo (#48), in the form the URL names.
     *
     * `icon`, `favicon` and `logo` are the file as it was kept: an uploaded
     * logo has no glyph to stand bare and no paints to repaint for dark
     * chrome, so the tile, the tab and the top bar all wear it as drawn.
     * `bleed` puts it on a ground to the edge (custom-bleed.svg.twig).
     *
     * The hash names the contents, so the URL is as immutable as a motif's;
     * the key says whose file to read. A logo that is gone (replaced since
     * the page was rendered, or never there) is a 404 rather than a stand-in:
     * every page that links one is rendered after its upload.
     *
     * The file is someone's upload, sanitised (SvgSanitizer), and this is the
     * second lock on it: a policy that runs nothing and fetches nothing, for
     * whoever opens the URL on its own rather than as an image.
     */
    #[Route(
        '/branding/{form}/custom/{hash}.svg',
        name: 'app_branding_custom',
        requirements: ['form' => 'icon|favicon|logo|bleed', 'hash' => CustomLogoStorage::HASH_PATTERN],
        methods: ['GET'],
    )]
    public function customSvg(Request $request, string $form, string $hash): Response
    {
        $key  = (string) $request->query->get('u', '');
        $path = $this->customLogos->pathFor($key, $hash);

        if (null === $path) {
            throw $this->createNotFoundException();
        }

        $response = $this->serve(
            $request,
            sprintf('custom.%s.%s.%s', $form, $key, $hash),
            fn (): string => 'bleed' === $form ? $this->customBleed((string) file_get_contents($path)) : (string) file_get_contents($path),
        );

        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** The install manifest for the uploaded logo; see manifest(). */
    #[Route(
        '/branding/manifest/custom/{hash}.webmanifest',
        name: 'app_branding_custom_manifest',
        requirements: ['hash' => CustomLogoStorage::HASH_PATTERN],
        methods: ['GET'],
    )]
    public function customManifest(Request $request, string $hash): Response
    {
        $key = (string) $request->query->get('u', '');

        if (null === $this->customLogos->pathFor($key, $hash)) {
            throw $this->createNotFoundException();
        }

        return $this->serve(
            $request,
            sprintf('manifest.custom.%s.%s', $key, $hash),
            fn (): string => $this->manifestWith(fn (string $name): string => $this->icons->customAppIconUrl($name, $key, $hash)),
            'application/manifest+json',
        );
    }

    /** One installed-app PNG of the uploaded logo; see appIcon(). */
    #[Route(
        '/branding/app-icon/{key}/{name}/custom/{hash}.png',
        name: 'app_branding_custom_app_icon',
        requirements: [
            'key'  => AppIconStorage::KEY_PATTERN,
            'name' => 'tile-192|tile-512|bleed-512|bleed-180',
            'hash' => CustomLogoStorage::HASH_PATTERN,
        ],
        methods: ['GET'],
    )]
    public function customAppIcon(Request $request, string $key, string $name, string $hash): Response
    {
        return $this->png($request, $key, $name, LogoIcons::customChoice($hash));
    }

    /**
     * A user's stored PNG for one choice, or the wax seal's of the same shape
     * while theirs is missing; see appIcon().
     */
    private function png(Request $request, string $key, string $name, string $choice): Response
    {
        $version = $this->icons->version();
        $stored  = $this->appIcons->pathFor($key, $choice, $version, $name);

        if (null === $stored) {
            $response = new Response(headers: ['Content-Type' => 'image/png']);
            $response->setPublic();
            $response->headers->addCacheControlDirective('no-cache');
            $response->setEtag('app-icon.fallback.' . $name . '.' . $version);

            if ($response->isNotModified($request)) {
                return $response;
            }

            return $response->setContent((string) file_get_contents($this->projectDir . '/public/' . AppIconStorage::FALLBACK[$name]));
        }

        // The upload can be replaced under the same URL (the same choice drawn
        // again), so the ETag is the file's, not only the URL's.
        return $this->serve(
            $request,
            sprintf('app-icon.%s.%s.%s.%s', $key, $choice, $name, (string) filemtime($stored)),
            static fn (): string => (string) file_get_contents($stored),
            'image/png',
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

    /**
     * The static manifest with the icons replaced: the user's PNGs of this
     * choice when there is a key, the file's own wax seal otherwise.
     */
    private function manifestJson(LogoMotif $motif, ?LogoStyle $paint, ?string $key): string
    {
        return $this->manifestWith(null === $key
            ? null
            : fn (string $name): string => $this->icons->appIconUrl($name, $key, $motif, $paint));
    }

    /**
     * The static manifest, with its icons replaced by the PNGs $url names, or
     * as it is when $url is null.
     *
     * @param (\Closure(string): string)|null $url the URL of one AppIconStorage::ICONS name
     */
    private function manifestWith(?\Closure $url): string
    {
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode(
            (string) file_get_contents($this->projectDir . '/public/manifest.webmanifest'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (null !== $url) {
            $icon = static fn (string $name, string $purpose): array => [
                'src'     => $url($name),
                'sizes'   => sprintf('%1$dx%1$d', AppIconStorage::ICONS[$name]),
                'type'    => 'image/png',
                'purpose' => $purpose,
            ];

            $manifest['icons'] = [
                $icon('tile-192', 'any'),
                $icon('tile-512', 'any'),
                $icon('bleed-512', 'maskable'),
            ];
        }

        return json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * One tile: the glyph on its ground, clipped to the rounded square, or
     * with the ground out to every edge when $bleed (see icon.svg.twig).
     */
    private function tile(LogoMotif $motif, ?LogoStyle $paint, bool $bleed = false): string
    {
        [$parts, $glyphRamp, $tileRamp] = self::fill($motif->paints($paint));

        return $this->renderView('branding/icon.svg.twig', [
            'motif'      => $motif,
            'p'          => $parts,
            'glyph_ramp' => $glyphRamp,
            'tile_ramp'  => $tileRamp,
            'bleed'      => $bleed,
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
     * The uploaded logo on a ground to every edge: its root placed in the
     * middle 72 of the 108 canvas and nested in custom-bleed.svg.twig.
     *
     * Through the DOM rather than by editing the text, because the root's own
     * x, y, width and height (if it has them) have to be replaced, not added
     * to. The file is the sanitised one, so it parses.
     */
    private function customBleed(string $svg): string
    {
        $document = new \DOMDocument();
        $document->loadXML($svg, LIBXML_NONET);

        $root = $document->documentElement;

        if (null !== $root) {
            foreach (['x' => '18', 'y' => '18', 'width' => '72', 'height' => '72'] as $attribute => $value) {
                $root->setAttribute($attribute, $value);
            }
        }

        return $this->renderView('branding/custom-bleed.svg.twig', [
            'logo' => null === $root ? '' : (string) $document->saveXML($root),
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
