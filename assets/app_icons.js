import { csrfToken } from './csrf.js';

/**
 * The installed app's icons, drawn by this browser and handed to the server
 * (#141).
 *
 * An app installed from the browser, or put on an iPhone's home screen, takes
 * its icon from PNGs: the manifest's, and iOS's apple-touch-icon. The logo is
 * an SVG with 330 faces. Drawing those PNGs on the server took librsvg in the
 * image, about 60 MB for everybody, and the Imagick already there paints a
 * gradient stroke black. A browser draws the SVG correctly by definition, so
 * the page draws it on a canvas, at the four sizes, and uploads them. The
 * server checks each one is a PNG of exactly its size and keeps it for this
 * user only (AppIconStorage).
 *
 * Two callers: the appearance pane when a logo is picked, and any page whose
 * user has no PNGs for their current choice yet (core--app-icons).
 */

/** name => [which SVG, pixels square]; the names AppIconStorage::ICONS uses. */
const ICONS = {
    'tile-192': ['tile', 192],
    'tile-512': ['tile', 512],
    'bleed-512': ['bleed', 512],
    'bleed-180': ['bleed', 180],
};

/**
 * One SVG drawn as a PNG of $size by $size.
 *
 * The SVG's own width and height are set to the target first, so the browser
 * rasterises the vector at that size rather than scaling up a 72-pixel
 * drawing. It is loaded from a blob URL: same origin, so the canvas is not
 * tainted and toBlob() is allowed (the layout's img-src allows blob:).
 */
async function draw(svgUrl, size) {
    const response = await fetch(svgUrl, { credentials: 'same-origin' });

    if (!response.ok) {
        throw new Error(`${svgUrl} answered ${response.status}`);
    }

    const svg = new DOMParser().parseFromString(await response.text(), 'image/svg+xml').documentElement;

    svg.setAttribute('width', String(size));
    svg.setAttribute('height', String(size));

    const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(svg)], { type: 'image/svg+xml' }));

    try {
        const image = new Image();
        image.src = url;
        await image.decode();

        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        canvas.getContext('2d').drawImage(image, 0, 0, size, size);

        return await new Promise((resolve, reject) => {
            canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('toBlob gave nothing'))), 'image/png');
        });
    } finally {
        URL.revokeObjectURL(url);
    }
}

/**
 * Draw the four PNGs of one choice and upload them.
 *
 * $tileUrl and $bleedUrl are the choice's tile and bleed SVGs as the server
 * generated them: `/branding/{icon|bleed}/{motif}/{paint}.svg?v={version}`,
 * so the choice and the drawing's version are read off the tile's URL rather
 * than passed alongside it, and cannot disagree with what was drawn.
 *
 * Resolves to the upload's status. 409 is not an error: the page is older
 * than the drawing (a deploy happened), and the next page load draws the
 * current one.
 */
export async function uploadAppIcons({ uploadUrl, tileUrl, bleedUrl }) {
    const tile = new URL(tileUrl, window.location.origin);
    const [, motif, paint] = tile.pathname.match(/\/([^/]+)\/([^/]+)\.svg$/) ?? [];

    if (!motif || !paint) {
        throw new Error(`not a logo URL: ${tileUrl}`);
    }

    const body = new FormData();
    body.append('motif', motif);
    body.append('paint', paint);
    body.append('version', tile.searchParams.get('v') ?? '');

    for (const [name, [form, size]] of Object.entries(ICONS)) {
        body.append(name, await draw(form === 'tile' ? tileUrl : bleedUrl, size), `${name}.png`);
    }

    const response = await fetch(uploadUrl, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken() },
        body,
    });

    return response.status;
}
