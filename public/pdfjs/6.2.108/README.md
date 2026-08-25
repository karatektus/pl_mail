# pdf.js 6.2.108, vendored

Mozilla's PDF renderer, committed rather than installed. plMail renders PDF attachments in the
browser because the server has no PDF capability and is not gaining any — see
`App\Service\Mail\AttachmentThumbnailer`, whose docblock says as much, and the Dockerfile, which
must build on arm64 as well as amd64.

## Why this is not in `importmap.php`

pdf.js needs `cMapUrl`, `standardFontDataUrl` and `wasmUrl` as **directory prefixes** — it
concatenates bare filenames onto them at runtime (`${wasmUrl}${filename}`). AssetMapper digests
every filename it compiles, so a stable directory under `/assets/` cannot exist, and `extraFiles`
in `assets/vendor/installed.php` is populated only from `url()` matches inside CSS. There is no
mechanism to declare arbitrary companion files for a JS package.

`public/` is Caddy's `root`, so this is served as static files with correct MIME types and no
application code, exactly like `public/icons` and `public/sw.js`. The version is in the path, so an
upgrade is: add the new directory, bump `App\Twig\PdfJsExtension::VERSION`, delete the old one.

`@cantoo/pdf-lib` — the write side, used for signing — *does* go through the importmap, because it
has no worker and no data directory. Two mechanisms, each where it fits.

## What is deliberately absent

**The `.wasm` binaries.** pdf.js 6 uses WebAssembly for JBIG2, JPEG 2000 and ICC colour, and the
enforced Content Security Policy carries no `'wasm-unsafe-eval'`, so `WebAssembly.instantiate`
throws. The package ships pure-JS fallbacks and the loader uses them when `useWasm: false` — which
the viewer passes. Shipping the `.wasm` files anyway would be dead weight that the browser is
forbidden to run, and *attempting* them logs a CSP violation on every scanned PDF, which would fail
`tests/e2e/csp.spec.ts`.

The cost is that JPEG 2000 and JBIG2 scans decode in JavaScript — slower on exactly the scanned
documents people want to sign. If that ever proves too slow, the one change is adding
`'wasm-unsafe-eval'` to `script-src` in `ContentSecurityPolicyListener::FULL`; it permits compiling
WebAssembly but does not re-enable string-to-code the way `'unsafe-eval'` does. Measure first.

Also absent: `iccs/` (needs the WASM path), `legacy/`, `image_decoders/`, `types/`, `web/` (the
reference viewer — plMail has its own), and the unminified builds and source maps.

## How this directory was produced

```bash
npm pack pdfjs-dist@6.2.108
tar xzf pdfjs-dist-6.2.108.tgz
V=6.2.108; DEST="public/pdfjs/$V"
mkdir -p "$DEST/build" "$DEST/wasm"
cp package/build/pdf.min.mjs package/build/pdf.worker.min.mjs "$DEST/build/"
cp -r package/cmaps package/standard_fonts "$DEST/"
cp package/wasm/jbig2_nowasm_fallback.js package/wasm/openjpeg_nowasm_fallback.js "$DEST/wasm/"
cp package/wasm/LICENSE_* "$DEST/wasm/"
cp package/LICENSE "$DEST/"
```

Licences: `LICENSE` (Apache-2.0, pdf.js) and `wasm/LICENSE_*` for the bundled decoders.
