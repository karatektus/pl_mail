<?php

declare(strict_types=1);

namespace App\Service\Appearance;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use App\Domain\Helper\ColourHelper;
use App\Domain\Theme\Colourway;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the logo icons live, and which drawing they are.
 *
 * Every icon a page shows is a URL: the topbar's, the settings pane's forty-two
 * tiles, the tab icon. This is the one place those URLs are spelled, so the
 * cache-busting in them cannot be done three ways.
 *
 * WHY A VERSION IN THE URL, AND WHY THIS ONE
 * ──────────────────────────────────────────
 * An icon at /branding/icon/{motif}/{paint}.svg (or at the tab icon's and the
 * top bar's routes beside it) depends on nothing but its URL and the code — no
 * user, no session — so it can be cached like a static file:
 * publicly, for a year, `immutable`. The settings pane shows forty-two of them
 * and the topbar one on every page, and none of that should ever be asked for
 * twice. What it cannot survive is a design change. Somebody tweaks a glyph, and
 * a year-long cache keeps the old one on every screen that ever loaded it.
 *
 * So the URL carries a version (`?v=`), and the version is a fingerprint of
 * everything an icon is drawn from: the tile and bare-glyph templates, the ten
 * glyph templates, the mark macro, and the classes holding the recipes and the
 * colours. Change any of them and every icon URL changes with it; change none
 * and they stay put across deploys, so an upgrade that did not touch the drawing
 * costs nobody a download. A hand-bumped constant was the alternative and was
 * rejected for the way it fails: forgotten once, it pins the old drawing in
 * caches for a year with nothing anywhere saying why.
 *
 * An ETag alone was the other alternative, and it is kept — but as the
 * fallback, not the mechanism. Revalidating forty-two tiles on every visit is
 * forty-two round trips to learn nothing; the ETag is for the requests that
 * arrive without the current version (a client that builds the URL itself, a
 * page rendered before a deploy), which get a short lifetime and a cheap 304.
 * BrandingController applies both halves.
 *
 * The fingerprint hashes the sources rather than the drawings: reading fifteen
 * small files is cheap enough to do once per process, while drawing all 330
 * icons to hash the results is not. It is over-eager on purpose — a comment
 * edited in a recipe file moves every URL once — which costs one download per
 * icon and is the right direction to be wrong in.
 */
final class LogoIcons
{
    /**
     * The drawing's sources that are not classes, relative to the project.
     * The classes are found through reflection, so a move cannot orphan them.
     *
     * @var list<string>
     */
    private const array TEMPLATES = [
        'templates/branding/*.svg.twig',
        'templates/branding/motif/*.svg.twig',
        'templates/_partials/_logo_mark.html.twig',
    ];

    /** @var list<class-string> */
    private const array CLASSES = [LogoMotif::class, LogoStyle::class, Colourway::class, ColourHelper::class];

    private ?string $version = null;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%kernel.project_dir%')]
        private readonly string                $projectDir,
    ) {}

    /** One icon tile: a motif in one paint, null being its own original design. */
    public function url(LogoMotif $motif, ?LogoStyle $paint = null): string
    {
        return $this->generate('app_branding_icon', $motif, $paint);
    }

    /**
     * The tab icon for one choice.
     *
     * The choice is in the path, and the route draws what the path says. It
     * must not draw from the session: the appearance pane points the tab here
     * the moment a pick is clicked, before the save lands (see
     * BrandingController).
     */
    public function faviconUrl(LogoMotif $motif, ?LogoStyle $paint = null): string
    {
        return $this->generate('app_branding_favicon', $motif, $paint);
    }

    /**
     * The top bar's logo for one choice: the glyph bare, for light chrome or
     * for dark.
     */
    public function logoUrl(LogoMotif $motif, ?LogoStyle $paint = null, bool $dark = false): string
    {
        return $this->generate('app_branding_logo', $motif, $paint, $dark ? ['dark' => 1] : []);
    }

    /** `<motif>.<paint>` — a choice as the ETags spell it. */
    public static function choice(LogoMotif $motif, ?LogoStyle $paint): string
    {
        return sprintf('%s.%s', $motif->value, LogoMotif::paintWire($paint));
    }

    /**
     * The drawing's fingerprint: twelve hex characters, the same on every
     * machine serving the same code.
     *
     * Memoised for the life of the process. In a worker that is until the next
     * deploy restarts it, which is also the only time the answer can change.
     */
    public function version(): string
    {
        return $this->version ??= $this->fingerprint();
    }

    /**
     * The paint is the path's last segment, before `.svg`, and every query
     * parameter comes after it: the appearance pane builds each colourway's
     * URL by swapping that one segment in the motif's own URL.
     *
     * @param array<string, int> $query
     */
    private function generate(string $route, LogoMotif $motif, ?LogoStyle $paint, array $query = []): string
    {
        return $this->urls->generate($route, [
            'motif' => $motif->value,
            'paint' => LogoMotif::paintWire($paint),
            ...$query,
            'v'     => $this->version(),
        ]);
    }

    private function fingerprint(): string
    {
        $files = [];

        foreach (self::TEMPLATES as $pattern) {
            array_push($files, ...(glob($this->projectDir . '/' . $pattern) ?: []));
        }

        foreach (self::CLASSES as $class) {
            $files[] = (string) new \ReflectionClass($class)->getFileName();
        }

        // Sorted here rather than trusted to glob(), whose order follows the
        // locale's collation — and two containers disagreeing about the order
        // would disagree about every URL.
        sort($files, SORT_STRING);

        $context = hash_init('xxh128');

        foreach ($files as $file) {
            hash_update_file($context, $file);
        }

        return substr(hash_final($context), 0, 12);
    }
}
