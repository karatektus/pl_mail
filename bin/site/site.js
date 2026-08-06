/**
 * The two pieces of behaviour the site has: search, and the theme switch.
 *
 * No framework and no build step. The site is static pages of prose; a bundler
 * here would be a toolchain to keep working for the sake of ninety lines.
 */

// ── Theme ────────────────────────────────────────────────────────────────────
//
// The seven plMail itself offers, generated into the stylesheet from
// Theme::swatch() so the site cannot end up offering six of them.
//
// "system" carries no palette and is the absence of data-theme, which is what
// the prefers-color-scheme block in the stylesheet answers. Stored per browser,
// because somebody who chose Paper on a dark-mode laptop meant Paper.

const themePicker = document.getElementById("theme");

function applyTheme(value) {
    if ("system" === value) {
        delete document.documentElement.dataset.theme;
    } else {
        document.documentElement.dataset.theme = value;
    }
}

if (null !== themePicker) {
    // Solar, matching the inline script in the shell that has already applied it
    // before paint. The two defaults have to agree: if they disagree the picker
    // shows one theme and the page wears another.
    themePicker.value = localStorage.getItem("plmail-theme") ?? "solar";
    applyTheme(themePicker.value);

    themePicker.addEventListener("change", () => {
        localStorage.setItem("plmail-theme", themePicker.value);
        applyTheme(themePicker.value);
    });
}

// ── Language ─────────────────────────────────────────────────────────────────
//
// A handbook in another language lives at docs/<locale>/…, so switching is a
// path rewrite and nothing more. Locales with no handbook are rendered disabled
// by the shell and can never be selected, so there is no unreachable branch here
// for a translation that does not exist.

const languagePicker = document.getElementById("language");

if (null !== languagePicker) {
    const root = languagePicker.dataset.root ?? "";
    const path = window.location.pathname;
    const known = [...languagePicker.options].map((option) => option.value);
    const current = known.find((code) => path.includes(`/docs/${code}/`)) ?? "en";

    languagePicker.value = current;

    /** Where a given page lives in another language. */
    const inLanguage = (wanted) => {
        // The landing page is README.md and has no translation, so there is no
        // counterpart of it to go to — and its URL carries no /docs/ segment to
        // rewrite, which is why switching language there once did nothing at
        // all. The handbook's index is the nearest honest destination.
        if (false === path.includes("/docs/")) {
            return "en" === wanted ? `${root}docs/` : `${root}docs/${wanted}/`;
        }

        // English is the tree's root; every other language is a directory
        // inside it, which is why this is two rules rather than one.
        const stripped = "en" === current ? path : path.replace(`/docs/${current}/`, "/docs/");

        return "en" === wanted ? stripped : stripped.replace("/docs/", `/docs/${wanted}/`);
    };

    languagePicker.addEventListener("change", () => {
        // Remembered, because the language has to survive more than the click.
        // The sidebar keeps a reader inside their language on its own — those
        // links are built per page — but arriving from the landing page, from a
        // bookmark or from somebody else's link lands on English every time,
        // and being bounced back to English on every fresh visit is the whole
        // complaint the picker was meant to answer.
        localStorage.setItem("plmail-language", languagePicker.value);
        window.location.href = inLanguage(languagePicker.value);
    });

    // Landed on a page that is not in the remembered language: go to the one
    // that is. This cannot loop — the redirect lands where `current` equals the
    // remembered language, so the condition is false on arrival — and it cannot
    // strand anybody in German, because choosing English is what sets the
    // memory to English.
    //
    // `replace` rather than `href`, so Back leaves the site instead of bouncing
    // between the two languages.
    const remembered = localStorage.getItem("plmail-language");

    // Not on the landing page. It has no translation and is the front door, so
    // following the memory there threw somebody who once chose German straight
    // into the handbook whenever they opened the site — which is a redirect
    // away from the page they asked for, not a language preference.
    if (null !== remembered
        && remembered !== current
        && true === known.includes(remembered)
        && true === path.includes("/docs/")
    ) {
        window.location.replace(inLanguage(remembered));
    }
}

// ── The navigation drawer ────────────────────────────────────────────────────
//
// Below the breakpoint the sidebar is off-canvas and this opens it. Above it,
// the button is display:none and none of this ever runs — there is no second
// layout to keep in step, just one sidebar the stylesheet places differently.
//
// The scrim is created here rather than sitting in every page's HTML: it is
// pure behaviour, it means nothing without JavaScript, and a page rendered
// without JS should not carry a dead overlay element.

const burger = document.getElementById("menu");
const drawer = document.getElementById("sidebar");

if (null !== burger && null !== drawer) {
    const scrim = document.createElement("div");

    scrim.className = "scrim";
    document.body.appendChild(scrim);

    const setOpen = (open) => {
        burger.setAttribute("aria-expanded", open ? "true" : "false");
        drawer.classList.toggle("is-open", open);
        scrim.classList.toggle("is-open", open);
        // The page behind a drawer must not scroll under it — otherwise a
        // flick meant for the menu takes the article with it.
        document.body.style.overflow = open ? "hidden" : "";
    };

    const isOpen = () => "true" === burger.getAttribute("aria-expanded");

    burger.addEventListener("click", () => setOpen(false === isOpen()));
    scrim.addEventListener("click", () => setOpen(false));

    // Following a link navigates, and the new page arrives with the drawer
    // shut; closing it first stops the old one animating away underneath.
    drawer.addEventListener("click", (event) => {
        if (null !== event.target.closest("a")) {
            setOpen(false);
        }
    });

    document.addEventListener("keydown", (event) => {
        if ("Escape" === event.key && true === isOpen()) {
            setOpen(false);
            burger.focus();
        }
    });

    // The pickers live in the top bar on a wide screen and at the foot of the
    // drawer on a narrow one.
    //
    // Moved rather than duplicated: two copies would mean two elements with the
    // same id, which breaks getElementById and therefore both pickers. And
    // moved by script rather than by CSS because no stylesheet can reparent a
    // node — the alternative was a second markup block, which is the thing that
    // drifts.
    //
    // It solves a real fight for width. Four controls do not fit across 375px;
    // with all of them in the bar the search box was squeezed to 24 pixels,
    // which is not a search box.
    const pickers = document.querySelector(".pickers");
    const bar = null === pickers ? null : pickers.parentElement;
    const narrow = window.matchMedia("(max-width: 60rem)");

    const placePickers = () => {
        if (null === pickers || null === bar) {
            return;
        }

        if (true === narrow.matches) {
            drawer.appendChild(pickers);
            pickers.classList.add("pickers-drawer");
        } else {
            bar.appendChild(pickers);
            pickers.classList.remove("pickers-drawer");
        }
    };

    placePickers();

    narrow.addEventListener("change", () => {
        placePickers();

        // Dragged past the breakpoint with the drawer open, the sidebar becomes
        // static again while body overflow is still hidden — a page that cannot
        // be scrolled and no visible reason why.
        if (false === narrow.matches) {
            setOpen(false);
        }
    });
}

// ── Wide tables ──────────────────────────────────────────────────────────────
//
// Wrapped here rather than in the generator, because CommonMark's table
// extension emits a bare <table> and the alternative is post-processing the
// HTML with a regular expression on the way out. A table that overflows its
// column widens the whole document on a phone, and the body scrolling sideways
// is the single ugliest thing a docs site can do.

document.querySelectorAll(".prose table").forEach((table) => {
    const wrap = document.createElement("div");

    wrap.className = "table-wrap";
    table.parentNode.insertBefore(wrap, table);
    wrap.appendChild(table);
});

// ── Search ───────────────────────────────────────────────────────────────────
//
// Every word of the handbook is about 300KB of JSON, which is small enough to
// fetch whole and search in the browser, and doing so buys the thing a hosted
// index cannot: the site is a directory of files, so it works from a checkout,
// from a fork's Pages, and behind whatever proxy somebody puts it behind.
//
// Fetched on first focus rather than on load. Almost nobody searches, and
// nobody should pay 300KB for a page they are reading.

const input = document.getElementById("search");
const list = document.getElementById("results");

let index = null;

async function load() {
    if (null !== index) {
        return;
    }

    const response = await fetch(
        `${input.dataset.root}assets/search-${input.dataset.locale ?? "en"}.json`,
    );

    index = await response.json();
}

function render(matches) {
    list.innerHTML = "";

    if (0 === matches.length) {
        list.hidden = true;

        return;
    }

    for (const page of matches.slice(0, 12)) {
        const item = document.createElement("li");
        const link = document.createElement("a");

        link.href = input.dataset.root + page.href;
        link.innerHTML = `<strong></strong><span></span>`;
        link.querySelector("strong").textContent = page.title;
        link.querySelector("span").textContent = page.excerpt;

        item.appendChild(link);
        list.appendChild(item);
    }

    list.hidden = false;
}

/** A slice of text that starts and ends on a word boundary. */
function whole(text, from, to) {
    const start = 0 === from ? 0 : text.indexOf(" ", from) + 1;
    const end = text.lastIndexOf(" ", to);

    return (0 < start ? "…" : "")
        + text.slice(start, -1 === end || end <= start ? to : end)
        + (to < text.length ? "…" : "");
}

function search(query) {
    const needle = query.trim().toLowerCase();

    if (2 > needle.length) {
        render([]);

        return;
    }

    const matches = [];

    for (const page of index) {
        const inTitle = page.title.toLowerCase().includes(needle);
        const at = page.text.toLowerCase().indexOf(needle);

        if (false === inTitle && -1 === at) {
            continue;
        }

        matches.push({
            title: page.title,
            href: page.href,
            // A window around the hit, so the result says why it matched —
            // snapped outward to whole words, because a window cut by character
            // count opens mid-syllable ("rrives in Outlook") and reads as a
            // rendering fault rather than as a quotation.
            excerpt: -1 === at
                ? whole(page.text, 0, 110)
                : whole(page.text, Math.max(0, at - 40), at + 90),
            // Title hits first: somebody typing "caldav" wants the CalDAV page,
            // not the eleven pages that mention it in passing.
            rank: true === inTitle ? 0 : 1,
        });
    }

    matches.sort((a, b) => a.rank - b.rank);
    render(matches);
}

if (null !== input) {
    input.addEventListener("focus", load, { once: true });

    input.addEventListener("input", async () => {
        await load();
        search(input.value);
    });

    // Escape closes it, and a click elsewhere closes it. Without both, the
    // panel sits over the page until something else is typed.
    input.addEventListener("keydown", (event) => {
        if ("Escape" === event.key) {
            input.value = "";
            render([]);
            input.blur();
        }
    });

    document.addEventListener("click", (event) => {
        if (false === event.target.closest(".search")) {
            list.hidden = true;
        }
    });
}
