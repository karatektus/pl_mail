/**
 * Template variables in the browser: the notation, the chips the editor draws
 * for it, and filling in a recipient.
 *
 * A module rather than a controller because two controllers need it and
 * neither owns it — the template editor in Settings draws tokens as chips, and
 * the compose window fills recipient variables when a recipient appears. The
 * picker's preview uses the same chips as the editor, so what is previewed is
 * drawn by the code that drew it when it was written.
 *
 * THE NOTATION is `{{name}}` or `{{name|key=value|key=value}}`, and it is the
 * server's: App\Service\Template\TemplateTokens reads the same grammar and its
 * docblock is the reference. The pattern below is that class's PATTERN.
 *
 * RECIPIENT VARIABLES ARE FILLED HERE AND NOWHERE ELSE. The server hands an
 * inserted template over with `recipient.*` still open (TemplateRenderer says
 * why), in two shapes:
 *
 *   in the HTML body     <span data-pl-var="recipient.first_name">First name</span>
 *   in text              {{recipient.first_name}}     (subject, plain-text body)
 *
 * and both are closed by the functions at the foot of this file. A variable
 * the recipient cannot answer — a first name, for an address typed with no
 * name — is left open rather than filled with a guess, and the window asks
 * about it before sending.
 */

const TOKEN = /\{\{\s*([a-z_.]+)\s*((?:\|[^|{}]*)*)\}\}/g;

/** The three the composer fills. Everything else the server already has. */
const RECIPIENT = ['recipient.first_name', 'recipient.name', 'recipient.email'];

// ── Notation ──────────────────────────────────────────────────────────────

/**
 * @param {string} name
 * @param {string} rawArguments  everything after the name, pipes included
 * @returns {{name: string, args: Object<string, string>}}
 */
function parse(name, rawArguments) {
    const args = {};

    for (const part of rawArguments.split('|')) {
        // On the FIRST equals sign: a custom date pattern may hold one.
        const at  = part.indexOf('=');
        const key = (-1 === at ? part : part.slice(0, at)).trim();

        if ('' !== key) {
            args[key] = -1 === at ? '' : part.slice(at + 1).trim();
        }
    }

    return { name, args };
}

/** One token's text, read. Null when it is not a token. */
export function parseToken(text) {
    const match = new RegExp(`^${TOKEN.source}$`).exec(text.trim());

    return null === match ? null : parse(match[1], match[2]);
}

/** A token written back out in the canonical form. */
export function writeToken({ name, args = {} }) {
    const parts = [name];

    for (const [key, value] of Object.entries(args)) {
        if ('' !== value) {
            parts.push(`${key}=${value}`);
        }
    }

    return `{{${parts.join('|')}}}`;
}

// ── Chips ─────────────────────────────────────────────────────────────────

/**
 * How a token reads on its chip: the variable's name, then whatever about it
 * is not the default.
 *
 * `words` is TemplateEditorViewData::chipLabels() — labels by variable name,
 * formats by preset, units by letter, signatures by token argument — built in
 * one place on the server so the editor and the picker cannot word the same
 * variable two ways.
 *
 * A named signature that is no longer among the choices — its account was
 * removed — reads as plain "Signature". That is also what it will do: the
 * renderer falls back to the automatic one.
 *
 * @param {{name: string, args: Object<string, string>}} token
 * @param {{labels?: Object, formats?: Object, units?: Object, signatures?: Object}} words
 */
export function describe(token, words) {
    const label = words.labels?.[token.name] ?? token.name;

    if ('date' === token.name) {
        const parts  = [label];
        const offset = /^([+-]?)(\d+)([dwm])$/.exec(token.args.offset ?? '');

        if (null !== offset && 0 !== Number(offset[2])) {
            const sign = '-' === offset[1] ? '\u2212' : '+';

            parts.push(`${sign}${Number(offset[2])} ${words.units?.[offset[3]] ?? offset[3]}`);
        }

        const format = token.args.format ?? '';

        if (format.startsWith('pattern:')) {
            parts.push(`\u00b7 ${format.slice(8)}`);
        } else if ('' !== format && 'long' !== format) {
            parts.push(`\u00b7 ${words.formats?.[format] ?? format}`);
        }

        return parts.join(' ');
    }

    if ('signature' === token.name) {
        const named = words.signatures?.[signatureArgument(token)];

        if (undefined !== named) {
            return `${label} \u00b7 ${named}`;
        }
    }

    return label;
}

/** `account=12` or `alias=7`: how a named signature is spelled in a token. */
export function signatureArgument(token) {
    if (token.args.account) {
        return `account=${token.args.account}`;
    }

    return token.args.alias ? `alias=${token.args.alias}` : '';
}

/**
 * Draw every token in `root`'s text as a chip.
 *
 * A chip is a non-editable span carrying its token in `data-pl-token`; the
 * token is the truth and the label is only how it reads. `known` is the list
 * of variable names, so that braces around anything else are left as the text
 * they are — the same rule the server applies.
 *
 * @param {Element} root
 * @param {string[]} known
 * @param {(token: {name: string, args: Object}) => string} describe
 */
export function chipify(root, known, describe) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const texts  = [];

    while (walker.nextNode()) {
        // Never inside a chip: its label is not template text.
        if (null === walker.currentNode.parentElement?.closest('[data-pl-token]')) {
            texts.push(walker.currentNode);
        }
    }

    for (const node of texts) {
        const text = node.nodeValue;
        const pattern = new RegExp(TOKEN.source, 'g');
        const pieces = document.createDocumentFragment();
        let last = 0;
        let found = false;
        let match;

        while (null !== (match = pattern.exec(text))) {
            if (false === known.includes(match[1])) {
                continue;
            }

            found = true;
            pieces.append(text.slice(last, match.index), chip(parse(match[1], match[2]), describe));
            last = match.index + match[0].length;
        }

        if (true === found) {
            pieces.append(text.slice(last));
            node.replaceWith(pieces);
        }
    }
}

/** A chip for one token. */
export function chip(token, describe) {
    const element = document.createElement('span');

    element.className = 'pl-var-chip';
    element.contentEditable = 'false';
    element.dataset.plToken = writeToken(token);
    element.textContent = describe(token);

    return element;
}

/**
 * The content of `root` with every chip turned back into its token — what is
 * stored. Works on a copy: the editor on screen keeps its chips.
 *
 * @returns {Element} a detached clone
 */
export function unchipped(root) {
    const clone = root.cloneNode(true);

    clone.querySelectorAll('[data-pl-token]').forEach((element) => {
        element.replaceWith(document.createTextNode(element.dataset.plToken));
    });

    return clone;
}

// ── Recipient ─────────────────────────────────────────────────────────────

/**
 * What a recipient chip says about a person.
 *
 * The compose window's chips read `Name (address)` for a known contact and the
 * bare address for one that was typed (Contact::__toString() and Tom Select's
 * create row). A first name is the first word of the name, or the part after
 * the comma when the name was stored surname-first — "Whitfield, Dana" is how
 * a directory writes it and "Hi Whitfield," is how that goes wrong.
 *
 * Deliberately no guessing from the address. "Hi d.whitfield," is worse than
 * a chip that says a name is missing.
 *
 * @param {string|null} label
 * @returns {Object<string, string>} variable name → value; '' when unknown
 */
export function recipientValues(label) {
    const text  = (label ?? '').trim();
    const match = /^(.*?)\s*\(([^()\s]+@[^()\s]+)\)$/.exec(text);

    let name  = '';
    let email = '';

    if (null !== match) {
        name  = match[1].trim();
        email = match[2];
    } else if (/^[^@\s]+@[^@\s]+$/.test(text)) {
        email = text;
    }

    // A contact with no name of its own is listed under its address, and an
    // address is not a name.
    if (name.includes('@')) {
        name = '';
    }

    const comma = name.indexOf(',');
    const given = -1 === comma ? name : name.slice(comma + 1).trim();

    return {
        'recipient.first_name': given.split(/\s+/)[0] ?? '',
        'recipient.name':       -1 === comma ? name : `${given} ${name.slice(0, comma).trim()}`.trim(),
        'recipient.email':      email,
    };
}

/**
 * Close every open recipient marker in `root` that `values` can answer.
 *
 * A filled marker becomes plain text, not a span with a value in it: from that
 * moment it is the user's writing, and a later change of recipient must not
 * reach back into a sentence somebody has already read and approved.
 *
 * @returns {boolean} whether anything changed
 */
export function fillMarkers(root, values) {
    let changed = false;

    root.querySelectorAll('[data-pl-var]').forEach((marker) => {
        const value = values[marker.dataset.plVar] ?? '';

        if ('' !== value) {
            marker.replaceWith(document.createTextNode(value));
            changed = true;
        }
    });

    return changed;
}

/** The same, for text: the subject line and a plain-text body. */
export function fillTokens(text, values) {
    return text.replace(new RegExp(TOKEN.source, 'g'), (whole, name) => {
        const value = values[name] ?? '';

        return RECIPIENT.includes(name) && '' !== value ? value : whole;
    });
}

/** Is a recipient variable still open in this text? */
export function hasOpenTokens(text) {
    return [...text.matchAll(new RegExp(TOKEN.source, 'g'))].some((match) => RECIPIENT.includes(match[1]));
}

/**
 * Markers written as tokens, for a body about to become plain text — a
 * textarea has no span to keep one in.
 *
 * @returns {Element} a detached clone
 */
export function markersAsTokens(root) {
    const clone = root.cloneNode(true);

    clone.querySelectorAll('[data-pl-var]').forEach((marker) => {
        marker.replaceWith(document.createTextNode(writeToken({ name: marker.dataset.plVar })));
    });

    return clone;
}
