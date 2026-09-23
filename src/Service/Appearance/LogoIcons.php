<?php

declare(strict_types=1);

namespace App\Service\Appearance;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use App\Domain\Helper\ColourHelper;
use App\Domain\Theme\Colourway;
use App\Entity\Embeddable\Appearance;
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
 * An icon at /branding/icon/{motif}/{paint}.svg depends on nothing but its URL
 * and the code — no user, no session — so it can be cached like a static file:
 * publicly, for a year, `immutable`. The settings pane shows forty-two of them
 * and the topbar one on every page, and none of that should ever be asked for
 * twice. What it cannot survive is a design change. Somebody tweaks a glyph, and
 * a year-long cache keeps the old one on every screen that ever loaded it.
 *
 * So the URL carries a version (`?v=`), and the version is a fingerprint of
 * everything an icon is drawn from: the icon and favicon templates, the ten
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

    /** One icon: a motif in one paint, null being its own original design. */
    public function url(LogoMotif $motif, ?LogoStyle $paint = null): string
    {
        return $this->urls->generate('app_branding_icon', [
            'motif' => $motif->value,
            'paint' => LogoMotif::paintWire($paint),
            'v'     => $this->version(),
        ]);
    }

    /**
     * The tab icon for one appearance.
     *
     * The favicon route answers per session, so the URL does not choose what is
     * drawn — it names it, because browsers keep favicons in a cache of their
     * own that ignores revalidation until a hard reload (see
     * _favicon.html.twig). `v` is the choice, which the appearance pane
     * rewrites live on every pick; `d` is the drawing, which only a deploy
     * changes, and which the pane therefore leaves alone.
     */
    public function faviconUrl(Appearance $appearance): string
    {
        return $this->urls->generate('app_branding_favicon', [
            'v' => self::choice($appearance->effectiveLogoMotif(), $appearance->effectiveLogoPaint()),
            'd' => $this->version(),
        ]);
    }

    /** `<motif>.<paint>` — a choice as the favicon's URL and ETag spell it. */
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
