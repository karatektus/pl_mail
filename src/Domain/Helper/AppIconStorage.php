<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * The installed app's icons, as PNGs the user's own browser drew (#141).
 *
 * iOS takes nothing but a PNG for its home-screen icon and a launcher wants
 * raster icons from the manifest, while the logo is an SVG with 330 faces.
 * Drawing those on the server needed librsvg in the image (about 60 MB for
 * everybody, for a feature only people with a home-screen icon and a
 * non-default logo notice), and the Imagick already in the image paints a
 * gradient stroke black. A browser draws the SVG correctly by definition, so
 * the appearance pane draws the four PNGs on a canvas and uploads them, and
 * this keeps them.
 *
 * WHY PER USER, AND WHY A KEY INSTEAD OF THE USER ID
 * ──────────────────────────────────────────────────
 * Per user, not shared per choice: an upload only ever changes its uploader's
 * own icons, so a page that uploads something odd draws it for nobody else.
 * A browser fetches a manifest and its icons without credentials, so the URL
 * has to say whose icons they are. It says so with an HMAC of the user id
 * rather than the id: nobody can walk the ids and collect everybody's logo.
 *
 * WHAT IS KEPT
 * ────────────
 * One choice per user: `<key>/<motif>.<paint>.<version>/<form>-<size>.png`. A
 * new upload replaces whatever was there, because only the current choice is
 * ever linked (an older one could only be named by a page loaded before the
 * pick, and that page's icon route falls back to the wax seal). The drawing's
 * version is in the directory, so a deploy that changes the drawing makes the
 * old PNGs missing, and the next page load draws them again.
 *
 * Not under `uploads/`: the weekly blob prune sweeps that tree for files no
 * row names (#214), and nothing here has a row. Losing these would cost
 * nothing worse than a redraw, but they have no business being swept.
 */
readonly class AppIconStorage
{
    /**
     * The four PNGs, by the name the upload and the URL use: the rounded tile
     * at a manifest's two sizes, the ground-to-the-edge form as its maskable
     * icon, and the same at 180 for iOS's apple-touch-icon.
     *
     * @var array<string, int> name => pixels square
     */
    public const array ICONS = [
        'tile-192'  => 192,
        'tile-512'  => 512,
        'bleed-512' => 512,
        'bleed-180' => 180,
    ];

    /**
     * The wax seal's static PNG for each, served when a user's own is missing,
     * so a manifest never names an icon that 404s.
     *
     * @var array<string, string> name => path under public/
     */
    public const array FALLBACK = [
        'tile-192'  => 'icons/icon-192.png',
        'tile-512'  => 'icons/icon-512.png',
        'bleed-512' => 'icons/icon-maskable-512.png',
        'bleed-180' => 'icons/apple-touch-icon.png',
    ];

    /**
     * Far above what a flat logo needs at 512 (a browser's PNG of one is tens
     * of KB), far below anything worth abusing.
     */
    public const int MAX_BYTES = 512 * 1024;

    /** A user's key as the URL spells it; the routes' requirement. */
    public const string KEY_PATTERN = '[0-9a-f]{20}';

    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function __construct(
        private string $projectDir,
        private string $storageDir,
        private string $secret,
    ) {
    }

    /** The URL's stand-in for a user id: stable, and not reversible to it. */
    public function keyFor(string $userId): string
    {
        return substr(hash_hmac('sha256', 'app-icons:' . $userId, $this->secret), 0, 20);
    }

    /**
     * Whether $bytes may be stored as $name: a real PNG, exactly the size the
     * name promises, and small. The browser's word is not taken for any of it.
     */
    public function isAcceptable(string $name, string $bytes): bool
    {
        $size = self::ICONS[$name] ?? null;

        if (null === $size || strlen($bytes) > self::MAX_BYTES || false === str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return false;
        }

        $info = @getimagesizefromstring($bytes);

        return false !== $info && IMAGETYPE_PNG === $info[2] && $size === $info[0] && $size === $info[1];
    }

    /**
     * Keep a user's four PNGs for one choice, replacing whatever they had.
     *
     * Written into a fresh directory and renamed into place, so a request
     * reading them never sees half a set.
     *
     * @param array<string, string> $pngs name => bytes, every name in ICONS, each isAcceptable()
     */
    public function store(string $userId, string $choice, string $version, array $pngs): void
    {
        $root    = $this->root($this->keyFor($userId));
        $staging = $root . '/.incoming-' . bin2hex(random_bytes(6));

        if (false === is_dir($staging) && false === mkdir($staging, 0755, true) && false === is_dir($staging)) {
            throw new \RuntimeException(sprintf('Could not create %s.', $staging));
        }

        foreach (self::ICONS as $name => $size) {
            file_put_contents($staging . '/' . $name . '.png', $pngs[$name]);
        }

        $final = $root . '/' . $this->set($choice, $version);

        $this->deleteTree($final);
        rename($staging, $final);

        // Only the choice just stored is ever linked; see the class comment.
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $other) {
            if ($other !== $final) {
                $this->deleteTree($other);
            }
        }
    }

    /** Whether the user's four PNGs for this choice and drawing are all there. */
    public function has(string $userId, string $choice, string $version): bool
    {
        $directory = $this->root($this->keyFor($userId)) . '/' . $this->set($choice, $version);

        foreach (array_keys(self::ICONS) as $name) {
            if (false === is_file($directory . '/' . $name . '.png')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The stored PNG for a key, choice, drawing and name, or null when there is
     * none. $key is the URL's, so it is checked here too, not only by the route.
     */
    public function pathFor(string $key, string $choice, string $version, string $name): ?string
    {
        if (1 !== preg_match('/^' . self::KEY_PATTERN . '$/', $key) || false === isset(self::ICONS[$name])) {
            return null;
        }

        $path = $this->root($key) . '/' . $this->set($choice, $version) . '/' . $name . '.png';

        return true === is_file($path) ? $path : null;
    }

    public function deleteAllFor(string $userId): void
    {
        $this->deleteTree($this->root($this->keyFor($userId)));
    }

    private function root(string $key): string
    {
        return $this->projectDir . '/' . $this->storageDir . '/app-icons/' . $key;
    }

    /** `<motif>.<paint>.<version>`: the choice as LogoIcons::choice() spells it, and the drawing. */
    private function set(string $choice, string $version): string
    {
        return $choice . '.' . $version;
    }

    private function deleteTree(string $directory): void
    {
        if (false === is_dir($directory)) {
            return;
        }

        // scandir, not glob: the staging directories start with a dot, and
        // glob's way of matching those (GLOB_BRACE) is missing on some libcs.
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;

            true === is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
