<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Where the vendored pdf.js lives, in one place.
 *
 * The viewer needs five URLs and three of them are DIRECTORY prefixes, because
 * pdf.js concatenates bare filenames onto them at runtime. That is the whole
 * reason this library is not in the importmap — AssetMapper digests filenames,
 * so `/assets/…/cmaps/` cannot be a stable prefix. See
 * public/pdfjs/<version>/README.md.
 *
 * The version appears once, here. An upgrade is: add the new directory, change
 * this constant, delete the old directory — and the trailing slashes matter,
 * since pdf.js appends directly to them.
 *
 * Not built with `asset()`: these files are served straight from `public/` by
 * Caddy and are deliberately not part of the asset map, so asking the mapper
 * for them would fail. That is the trade for having directories at all.
 */
final class PdfJsExtension extends AbstractExtension
{
    public const string VERSION = '6.2.108';

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('pdfjs_assets', $this->assets(...)),
        ];
    }

    /**
     * @return array{lib: string, worker: string, cMaps: string, fonts: string, wasm: string}
     */
    public function assets(): array
    {
        $base = '/pdfjs/'.self::VERSION;

        return [
            'lib'    => $base.'/build/pdf.min.mjs',
            'worker' => $base.'/build/pdf.worker.min.mjs',
            // Trailing slashes: pdf.js does `${cMapUrl}${name}.bcmap`.
            'cMaps'  => $base.'/cmaps/',
            'fonts'  => $base.'/standard_fonts/',
            'wasm'   => $base.'/wasm/',
        ];
    }
}
