/**
 * HTML from the clipboard, reduced to what the person copied (#807).
 *
 * The first version of this (CODE-REVIEW.md U-02) kept the tags and dropped
 * every `style` and `class`. Tags were all that survived, and most apps do not
 * say "bold" with a tag:
 *
 *  - Google Docs marks bold, italic and underline with inline CSS, and wraps
 *    the whole selection in `<b style="font-weight:normal">`. With the style
 *    gone, italic vanished and the entire paste turned bold.
 *  - Pages, TextEdit and Word put formatting in a stylesheet of their own and
 *    refer to it by class (`<span class="s1">`). The stylesheet was dropped, so
 *    the formatting went with it.
 *  - Docs, Word and Pages send every line as a `<p>` they draw with no margin.
 *    Stripped of that, each one got a mail client's 1em above and below, and a
 *    single-spaced text arrived double-spaced. Word's blank line,
 *    `<p>&nbsp;</p>`, then added a gap on top of the gaps.
 *
 * So the formatting is now read off the pasted document before anything is
 * thrown away: each element's inline style, plus every rule of the copied
 * stylesheet that matches it, in the order the sheet gives them. Specificity is
 * not ranked; for the flat class-per-run sheets these apps write it makes no
 * difference, and getting it wrong costs one run a wrong weight, not the paste.
 *
 * What is kept is the CSS that formats text: weight, style, decoration,
 * superscript and subscript, colour, highlight, font and size. What goes is the
 * CSS that lays out the source page — margins, line height, widths, positions —
 * and two colours that are the source's defaults rather than a choice: black
 * text, which turns into black on dark in dark mode, and a white or
 * transparent background. Classes always go: the stylesheet they point at is
 * not sent, and inside the editor they would meet the app's own CSS, where a
 * pasted `class="hidden"` makes text disappear.
 *
 * This is a convenience, not a security boundary. The server sanitises every
 * composed body on save (MailBodySanitizer::sanitizeComposedBody), and that is
 * what keeps script and positioning off the wire.
 */

/** Taken out with everything in them: none of it is content. */
const DROPPED = 'style, script, link, meta, title, iframe, object, embed, form, input, button, textarea, select';

/** The formatting a pasted run may keep, as longhand CSS properties. */
const KEPT = [
    'font-weight',
    'font-style',
    'text-decoration-line',
    'vertical-align',
    'color',
    'background-color',
    'font-family',
    'font-size',
];

/** Read only to decide whether a paragraph had a gap around it. */
const SPACING = ['margin-top', 'margin-bottom'];

/** Attributes that are content, on the elements that mean them. */
const KEPT_ATTRIBUTES = {
    A:   ['href'],
    IMG: ['src', 'alt'],
};

/**
 * @param {string} html the clipboard's text/html
 *
 * @returns {{ html: string, hasText: boolean }} the markup to insert, and
 *          whether it holds any text (as opposed to being only a picture)
 */
export function cleanPastedMarkup(html) {
    const doc  = new DOMParser().parseFromString(html, 'text/html');
    const body = doc.body;

    const fromSheets = stylesFromSheets(doc);

    // Read before anything moves: unwrapping and renaming below change the
    // tree, and a rule's selector has to see the document as it was copied.
    const styles = new Map();

    for (const element of body.querySelectorAll('*')) {
        styles.set(element, effectiveStyle(element, fromSheets.get(element)));
    }

    body.querySelectorAll(DROPPED).forEach((node) => node.remove());
    removeComments(body);

    for (const element of [...body.querySelectorAll('*')]) {
        rewrite(element, styles.get(element) ?? new Map(), doc);
    }

    return {
        html:    body.innerHTML,
        hasText: '' !== body.textContent.replace(/[\s ]/g, ''),
    };
}

/**
 * Every declaration the copied stylesheet gives each element, in sheet order.
 *
 * @returns {Map<Element, Map<string, string>>}
 */
function stylesFromSheets(doc) {
    const result = new Map();

    for (const style of doc.querySelectorAll('style')) {
        let sheet;

        try {
            sheet = new CSSStyleSheet();
            sheet.replaceSync(style.textContent ?? '');
        } catch {
            continue;
        }

        for (const rule of sheet.cssRules) {
            if (!(rule instanceof CSSStyleRule)) {
                continue;
            }

            let matches;

            try {
                matches = doc.body.querySelectorAll(rule.selectorText);
            } catch {
                // Word writes selectors no browser takes (`p.MsoNormal, o|p`).
                continue;
            }

            for (const element of matches) {
                const declarations = result.get(element) ?? new Map();

                for (const property of [...KEPT, ...SPACING]) {
                    const value = rule.style.getPropertyValue(property);

                    if ('' !== value) {
                        declarations.set(property, value);
                    }
                }

                result.set(element, declarations);
            }
        }
    }

    return result;
}

/** The stylesheet's declarations, then the element's own on top. */
function effectiveStyle(element, fromSheet) {
    const style = new Map(fromSheet ?? []);

    for (const property of [...KEPT, ...SPACING]) {
        const value = element.style?.getPropertyValue(property) ?? '';

        if ('' !== value) {
            style.set(property, value);
        }
    }

    // `<font color face>`, which older mail and some editors still write.
    if ('FONT' === element.tagName) {
        if (element.hasAttribute('color') && !style.has('color')) {
            style.set('color', element.getAttribute('color'));
        }

        if (element.hasAttribute('face') && !style.has('font-family')) {
            style.set('font-family', element.getAttribute('face'));
        }
    }

    return style;
}

function rewrite(element, style, doc) {
    const kept = formatting(style);

    for (const name of element.getAttributeNames()) {
        if (false === (KEPT_ATTRIBUTES[element.tagName] ?? []).includes(name)) {
            element.removeAttribute(name);
        }
    }

    if ('' !== kept) {
        element.setAttribute('style', kept);
    }

    // Word's `<o:p>` and every other namespaced element: the content stays.
    // Google Docs' `<b id="docs-internal-guid-…" style="font-weight:normal">`
    // is the same in effect — a wrapper that says "not bold" — and so is any
    // other bold or italic tag whose own style cancels it.
    if (element.tagName.includes(':') || cancelsItself(element, style)) {
        unwrap(element, kept, doc);

        return;
    }

    if ('P' === element.tagName) {
        rewriteParagraph(element, style, kept, doc);
    }
}

/** The kept declarations as a style attribute, the source's defaults left out. */
function formatting(style) {
    const parts = [];

    for (const property of KEPT) {
        const value = style.get(property);

        if (undefined === value || isDefault(property, value)) {
            continue;
        }

        // Written as the shorthand: Outlook's Word engine predates the
        // longhand and would drop the underline.
        parts.push(`${'text-decoration-line' === property ? 'text-decoration' : property}: ${value}`);
    }

    return parts.join('; ');
}

function isDefault(property, value) {
    const normalised = value.replace(/\s+/g, '').toLowerCase();

    switch (property) {
        case 'color':
            return ['black', 'windowtext', '#000', '#000000', 'rgb(0,0,0)', 'rgba(0,0,0,1)'].includes(normalised);
        case 'background-color':
            return ['transparent', 'white', 'window', '#fff', '#ffffff', 'rgb(255,255,255)', 'rgba(255,255,255,1)'].includes(normalised)
                || /^rgba\(.*,0\)$/.test(normalised);
        case 'vertical-align':
            // Only these two format text; the rest is table and image layout.
            return 'super' !== normalised && 'sub' !== normalised;
        default:
            return false;
    }
}

function cancelsItself(element, style) {
    const tag = element.tagName;

    if ('B' === tag || 'STRONG' === tag) {
        const weight = style.get('font-weight');

        return 'normal' === weight || ('' !== (weight ?? '') && parseInt(weight, 10) < 600);
    }

    if ('I' === tag || 'EM' === tag) {
        return 'normal' === style.get('font-style');
    }

    return false;
}

/**
 * A paragraph the source drew with no gap is a line, and an empty one is a
 * blank line. Anything else stays a paragraph, with the gap it had.
 */
function rewriteParagraph(paragraph, style, kept, doc) {
    const empty = '' === paragraph.textContent.replace(/[\s ]/g, '')
        && null === paragraph.querySelector('img');

    if (empty) {
        const line = doc.createElement('div');
        line.append(doc.createElement('br'));
        paragraph.replaceWith(line);

        return;
    }

    if (isZero(style.get('margin-top')) && isZero(style.get('margin-bottom'))) {
        const line = doc.createElement('div');

        if ('' !== kept) {
            line.setAttribute('style', kept);
        }

        line.append(...paragraph.childNodes);
        paragraph.replaceWith(line);
    }
}

function isZero(value) {
    return undefined !== value && 0 === parseFloat(value);
}

/**
 * Replaces an element with its children. What the element itself said about
 * the text (a colour on Docs' wrapper, say) moves onto a span around them.
 */
function unwrap(element, kept, doc) {
    const keptOwn = kept.split('; ').filter((part) => !part.startsWith('font-weight') && !part.startsWith('font-style')).join('; ');

    if ('' !== keptOwn) {
        const span = doc.createElement('span');
        span.setAttribute('style', keptOwn);
        span.append(...element.childNodes);
        element.replaceWith(span);

        return;
    }

    element.replaceWith(...element.childNodes);
}

function removeComments(root) {
    const walker = root.ownerDocument.createTreeWalker(root, NodeFilter.SHOW_COMMENT);
    const comments = [];

    while (walker.nextNode()) {
        comments.push(walker.currentNode);
    }

    comments.forEach((comment) => comment.remove());
}
