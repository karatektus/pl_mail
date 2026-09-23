<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use App\Entity\Embeddable\Appearance;
use App\Entity\User\User;
use App\Infrastructure\Routing\LogoPaintRequirement;
use App\Service\Appearance\LogoIcons;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;

/**
 * The logo, served as the viewer chose it: the tab icon, and the icon tiles.
 *
 * A favicon is a static file for a product whose mark has one face; plMail's
 * has three hundred and thirty now (Settings → Appearance → Logo: ten icons,
 * each in its own design or thirty-two colourways), and a <link> can only
 * point somewhere — so it points here, and here answers with the icon drawn
 * for whoever is asking. Anonymous requests get the product default, which is
 * also what public/icons/favicon.svg holds: the static file stays as the
 * fallback for anything that will not send a cookie, and this route is why
 * the two can never disagree for a signed-in user.
 *
 * The favicon is Cache-Control private: the answer depends on the session,
 * and a shared cache holding one user's berry for another's ink is exactly the
 * bug a favicon cannot visibly report. The real freshness mechanism is the URL:
 * _favicon.html.twig versions the link with the choice and the drawing (see
 * LogoIcons::faviconUrl()), so a changed choice is a different URL and the
 * browser's own favicon cache — which ignores ordinary revalidation until a
 * hard reload — never needs to be argued with. The ETag stays for the one
 * same-URL case (two tabs, one switch), where it turns the refetch into a 304.
 *
 * The icon tiles are the opposite case: they depend on the URL and nothing
 * else, so they are public and, at the current drawing version, immutable. See
 * icon() and LogoIcons for why.
 */
final class BrandingController extends AbstractController
{
    /**
     * A year: as long as a cache will keep anything. Safe only because the URL
     * changes when the drawing does — which is what the version check in
     * icon() makes sure of before promising it.
     */
    private const int IMMUTABLE = 31_536_000;

    /**
     * An hour, for an icon asked for without the current version. Short enough
     * that a deploy's new drawing reaches such a client the same day, long
     * enough that it is not a request per page; and every one after the first
     * is a 304 against the ETag.
     */
    private const int UNVERSIONED = 3_600;

    public function __construct(
        private readonly LogoIcons $icons,
    ) {}

    #[Route('/branding/favicon.svg', name: 'app_branding_favicon', methods: ['GET'])]
    public function favicon(Request $request): Response
    {
        $user = $this->getUser();

        $appearance = $user instanceof User ? $user->appearance : new Appearance();
        $motif = $appearance->effectiveLogoMotif();
        $paint = $appearance->effectiveLogoPaint();

        $response = new Response(headers: [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=604800',
        ]);

        // The choice and the drawing both: the same choice drawn by a newer
        // glyph is a different picture.
        $response->setEtag(LogoIcons::choice($motif, $paint) . '.' . $this->icons->version());

        if ($response->isNotModified($request)) {
            return $response;
        }

        // The pl mark stays the bare mark it has always been in a tab strip —
        // seven strokes on nothing, the most legible thing that fits in 16px.
        // Every other icon is its tile: a horn with no ground behind it is a
        // squiggle at that size, and the tile is what the icon IS on a phone.
        $response->setContent(LogoMotif::Pl === $motif
            ? $this->renderView('branding/favicon.svg.twig', ['style' => $appearance->effectiveLogoStyle()])
            : $this->tile($motif, $paint));

        return $response;
    }

    /**
     * One icon tile, as the settings pane, the topbar and anyone else draws it.
     *
     * Public, and outside the session firewall entirely (security.yaml gives
     * the path `security: false`). Nothing about the answer depends on who is
     * asking, and a request that so much as read the session would have Symfony
     * rewrite this response to `private, max-age=0` — the right default for a
     * page, and forty-two uncacheable images on the appearance pane.
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
        $version = $this->icons->version();

        $response = new Response(headers: ['Content-Type' => 'image/svg+xml']);
        $response->setPublic();
        $response->setEtag(LogoIcons::choice($motif, $style) . '.' . $version);

        // Immutable only for the URL LogoIcons hands out for this drawing.
        // Anything else — no version, or one from before a deploy — is served
        // the current drawing, but must not be told it can keep it forever:
        // that URL will not change when the drawing next does.
        if ($version === $request->query->get('v')) {
            $response->setMaxAge(self::IMMUTABLE);
            $response->setImmutable();
        } else {
            $response->setMaxAge(self::UNVERSIONED);
        }

        if ($response->isNotModified($request)) {
            return $response;
        }

        $response->setContent($this->tile($motif, $style));

        return $response;
    }

    /**
     * Draw one tile: the motif's paints from LogoMotif, turned into what the
     * glyph templates read.
     *
     * The table form ({ramp: [...]}) is what the export and the phone share;
     * the templates want a paint they can drop into `fill`. A ramp becomes a
     * reference to the one gradient the tile defines for it — every glyph part
     * that is a ramp is the same colourway's ramp, so one glyph gradient and
     * one ground gradient are all a tile ever needs.
     */
    private function tile(LogoMotif $motif, ?LogoStyle $paint): string
    {
        $parts = [];
        $glyphRamp = null;
        $tileRamp = null;

        foreach ($motif->paints($paint) as $part => $value) {
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

        return $this->renderView('branding/icon.svg.twig', [
            'motif'      => $motif,
            'p'          => $parts,
            'glyph_ramp' => $glyphRamp,
            'tile_ramp'  => $tileRamp,
        ]);
    }
}
