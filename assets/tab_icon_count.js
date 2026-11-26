/**
 * The Inbox's unread count on plMail's icon in the browser tab, as Gmail
 * draws it (#662). For somebody who switched it on in Settings →
 * Notifications, which the layout says by rendering
 * `<meta name="tab-icon-count">` with the count at page load; absent means off.
 *
 * Why the icon and not only the title: a pinned tab shows its icon and nothing
 * else, so the "(3)" the title already carries is not there to be seen.
 *
 * THE SAME ONE `<link rel="icon">`, ITS HREF SWAPPED. A second icon link would
 * be a second candidate, and _favicon.html.twig says what that did in Firefox.
 * The plain URL is kept on the link while a drawing is shown, and a plain URL
 * written over the drawing — the appearance pane does that on every pick, a
 * Turbo visit puts the page's own link back — is the new picture to draw on.
 *
 * DRAWN AS AN SVG, so the link keeps its `type="image/svg+xml"`: the icon's
 * own SVG, fetched from the same origin, nested whole in a 32 by 32 drawing
 * with the count over its corner, as a data: URL (the layout's img-src allows
 * data:). A PNG from a canvas would need the type changed, and the appearance
 * pane finds the link by that type.
 *
 * Called by ui--sidebar, which has the counts: on connect with the page's
 * number, and after every counts answer with `role:inbox` from it. And drawn
 * again here after every Turbo render, because each one puts the page's own
 * head in, plain icon included, and not each one reconnects the sidebar: the
 * Settings pages refresh by morphing (turbo-refresh-method), which keeps it.
 */

/**
 * The counts key the icon shows: the Inbox on every page, the number the
 * title counts on the Inbox. Of the workspace on screen when it shows only some
 * account groups (#783), as SidebarCounts scopes every count that spans accounts.
 */
export const TAB_ICON_COUNT_KEY = "role:inbox";

const META = 'meta[name="tab-icon-count"]';
const ICON = 'link[rel="icon"][type="image/svg+xml"]';
const SIZE = 32;
/**
 * The count's outline: black digits in the bottom right corner, each ringed in
 * white so they read on any icon, the red wax seal included, and on a light
 * and a dark tab strip alike, while covering no more of the icon than they
 * have to. In the drawing's units, a 32nd of the icon; half of it lies outside
 * the digits.
 */
const OUTLINE = 3;

/** The last icon SVG fetched, by URL: a new count is not a new request. */
let fetched = { url: null, svg: null };
/** The newest call; an older one still drawing must not land after it. */
let turn = 0;
/** The count last asked for, so the icon can be redrawn on a new picture. */
let lastCount = null;

if (typeof document !== "undefined") {
    document.addEventListener("turbo:render", () => showOnTabIcon(renderedTabIconCount()));
}

/** Whether this page's reader has the count on the tab icon switched on. */
export function tabIconCountOn() {
    return null !== document.querySelector(META);
}

/** The count the page was rendered with, or null when the switch is off. */
export function renderedTabIconCount() {
    const meta = document.querySelector(META);

    return null === meta ? null : Number(meta.content) || 0;
}

/**
 * Put $count on the tab icon, or take the badge off for 0.
 *
 * Idempotent and safe to call twice in one tick, which is what happens: the
 * sidebar partial is on the page twice, so two controllers report every count.
 */
export async function showOnTabIcon(count) {
    const link = document.querySelector(ICON);

    if (null === link || false === tabIconCountOn() || "number" !== typeof count) {
        return;
    }

    const mine = ++turn;
    lastCount = count;

    const href = link.getAttribute("href") ?? "";

    // A plain URL is the picture; a data: URL is a drawing of ours over the
    // picture kept beside it.
    if (false === href.startsWith("data:")) {
        link.dataset.tabIconPlain = href;
    }

    const plain = link.dataset.tabIconPlain;

    if (!plain) {
        return;
    }

    if (count <= 0) {
        if (href !== plain) {
            link.setAttribute("href", plain);
        }

        return;
    }

    let drawing;

    try {
        drawing = withBadge(await iconSvg(plain), count);
    } catch (e) {
        // The plain icon stays. The title still carries the count.
        return;
    }

    // A newer count, or a new picture, arrived while this one was fetching.
    if (mine !== turn || link.dataset.tabIconPlain !== plain || false === link.isConnected) {
        return;
    }

    const url = `data:image/svg+xml,${encodeURIComponent(drawing)}`;

    if (href !== url) {
        link.setAttribute("href", url);
    }
}

/**
 * Draw the last count again, on whatever picture the link now names. For the
 * appearance pane, which writes a new plain URL over the drawing on a pick.
 */
export function redrawTabIcon() {
    if (null !== lastCount) {
        showOnTabIcon(lastCount);
    }
}

async function iconSvg(url) {
    if (fetched.url === url) {
        return fetched.svg;
    }

    const response = await fetch(url, { credentials: "same-origin" });

    if (!response.ok) {
        throw new Error(`${url} answered ${response.status}`);
    }

    const svg = await response.text();
    fetched = { url, svg };

    return svg;
}

/**
 * The icon with the count over its bottom right corner. Past 99 it says
 * "99+": a fourth character would not be legible at sixteen pixels.
 */
function withBadge(svgText, count) {
    const svg = new DOMParser().parseFromString(svgText, "image/svg+xml").documentElement;

    if ("svg" !== svg.localName) {
        throw new Error("not an SVG");
    }

    // A drawing sized in pixels and without a viewBox would be cut to its top
    // left 32 pixels once its size is set below; this keeps all of it.
    const width = parseFloat(svg.getAttribute("width") ?? "");
    const height = parseFloat(svg.getAttribute("height") ?? "");

    if (!svg.hasAttribute("viewBox") && width > 0 && height > 0) {
        svg.setAttribute("viewBox", `0 0 ${width} ${height}`);
    }

    svg.setAttribute("x", "0");
    svg.setAttribute("y", "0");
    svg.setAttribute("width", String(SIZE));
    svg.setAttribute("height", String(SIZE));

    const text = count > 99 ? "99+" : String(count);
    const font = 3 === text.length ? 13 : 17;
    const edge = SIZE - OUTLINE / 2;

    // paint-order puts the white stroke under the black fill, so only its
    // outer half shows: an outline, not a fatter digit.
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${SIZE}" height="${SIZE}" viewBox="0 0 ${SIZE} ${SIZE}">`
        + new XMLSerializer().serializeToString(svg)
        + `<text x="${edge}" y="${edge}" fill="#000" stroke="#fff" stroke-width="${OUTLINE}" stroke-linejoin="round" paint-order="stroke"`
        + ` font-family="system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif" font-size="${font}" font-weight="700"`
        + ` text-anchor="end">${text}</text>`
        + "</svg>";
}
