/**
 * What the contact editor and quick add both need to know about a contact's
 * fields in the browser: how a name splits into its parts, which label an
 * address or number probably has, and whether two spellings are the same
 * number.
 *
 * splitName() mirrors ContactTextParser::splitName() on the server. Two
 * copies, on purpose: the editor re-splits on every keystroke in the name
 * field, and a round trip per keystroke for five words is the wrong price.
 * The server's copy is the one a pasted text and the AI's answer go
 * through; this one only fills the part fields as somebody types, and what
 * is saved is whatever those fields then hold. ContactTextParserTest pins
 * the server's rules; the e2e spec pins these to the same names.
 */

const PREFIXES = ["dr.", "dr", "prof.", "prof", "mr.", "mr", "mrs.", "mrs", "ms.", "ms", "herr", "frau", "dipl.-ing.", "ing.", "mag.", "m.", "mme", "mme."]
const SUFFIXES = ["jr.", "jr", "sr.", "sr", "phd", "ph.d.", "md", "m.d.", "ii", "iii", "iv", "mba", "msc", "m.sc.", "b.sc."]
const PARTICLES = ["van", "von", "vom", "zu", "zum", "de", "der", "den", "del", "della", "di", "da", "du", "la", "le", "ten", "ter", "af", "al", "bin", "ibn"]

const PERSONAL_DOMAINS = [
    "gmail.com", "googlemail.com", "gmx.de", "gmx.net", "gmx.at", "gmx.ch", "web.de", "t-online.de",
    "outlook.com", "outlook.de", "hotmail.com", "hotmail.de", "live.com", "icloud.com", "me.com", "mac.com",
    "yahoo.com", "yahoo.de", "posteo.de", "posteo.net", "mailbox.org", "proton.me", "protonmail.com",
    "freenet.de", "arcor.de", "aol.com", "orange.fr", "free.fr", "laposte.net",
]

export const NAME_PARTS = ["namePrefix", "givenName", "middleName", "familyName", "nameSuffix"]

/** @returns {Record<string, string>} NAME_PARTS → value, empty ones left out */
export function splitName(name) {
    const words = (name || "").trim().split(/\s+/).filter(Boolean)
    const parts = {}

    const prefix = []
    while (words.length > 1 && isTitle(words[0], prefix.length > 0)) prefix.push(words.shift())
    if (prefix.length) parts.namePrefix = prefix.join(" ")

    const suffix = []
    while (words.length > 1 && isDegree(words[words.length - 1].replace(/,$/, ""), words[words.length - 2].endsWith(","))) {
        suffix.unshift(words.pop().replace(/,$/, ""))
    }
    if (words.length) words[words.length - 1] = words[words.length - 1].replace(/,$/, "")

    if (words.length === 1) {
        parts.givenName = words[0]
    } else if (words.length > 1) {
        let family = words.length - 1
        for (let i = 1; i < words.length - 1; i++) {
            if (PARTICLES.includes(words[i])) { family = i; break }
        }
        parts.givenName = words[0]
        if (family > 1) parts.middleName = words.slice(1, family).join(" ")
        parts.familyName = words.slice(family).join(" ")
    }

    if (suffix.length) parts.nameSuffix = suffix.join(" ")

    return parts
}

/** A known title, an abbreviation of two or more letters, or a lower-case word after a title. */
function isTitle(word, afterTitle) {
    if (PREFIXES.includes(word.toLowerCase())) return true
    if (word.endsWith(".") && (word.match(/\p{L}/gu) || []).length >= 2) return true
    return afterTitle && /^\p{Ll}[\p{Ll}.]*$/u.test(word) && !PARTICLES.includes(word)
}

/** A known suffix, a dotted or short mixed-case degree, or an all-caps group after a comma. */
function isDegree(word, afterComma) {
    if (SUFFIXES.includes(word.toLowerCase())) return true
    if (/^(\p{L}{1,3}\.){2,}$/u.test(word)) return true
    if ([...word].length <= 5 && /^\p{Lu}(\p{Lu}+\p{Ll}+|\p{Ll}+\p{Lu})$/u.test(word)) return true
    return afterComma && /^\p{Lu}{2,}$/u.test(word)
}

export function joinName(parts) {
    return NAME_PARTS.map((k) => (parts[k] || "").trim()).filter(Boolean).join(" ")
}

/** home for a personal mailbox provider, work for any other domain, other before there is one. */
export function emailLabel(email) {
    const at = (email || "").lastIndexOf("@")
    if (at < 0) return "other"
    const domain = email.slice(at + 1).trim().toLowerCase()
    if (!domain.includes(".")) return "other"
    return PERSONAL_DOMAINS.includes(domain) ? "home" : "work"
}

/**
 * Whether a number is surely a mobile, for the label a new row starts with.
 * Only the countries whose mobile prefixes are unambiguous — Germany, Austria,
 * Switzerland, the UK, France, the Netherlands; anywhere else the row keeps the
 * label it has, and the server's libphonenumber reading is the one a pasted
 * number gets.
 */
export function looksMobile(number) {
    const d = (number || "").replace(/[^\d+]/g, "").replace(/^00/, "+")
    return /^(\+49|0)1[5-7]/.test(d)
        || /^(\+43|0)6[5-9]/.test(d)
        || /^(\+41|0)7[5-9]/.test(d)
        || /^(\+44|0)7[1-9]/.test(d)
        || /^(\+33|0)[67]/.test(d)
        || /^(\+31|0)6/.test(d)
}

/** The same number in two spellings: the last nine digits agree. */
export function sameNumber(a, b) {
    const da = (a || "").replace(/\D+/g, "")
    const db = (b || "").replace(/\D+/g, "")
    if (da.length < 6 || db.length < 6) return da === db
    return da.slice(-9) === db.slice(-9)
}

export function sameText(a, b) {
    const norm = (v) => (v || "").trim().replace(/\s+/g, " ").toLowerCase()
    return norm(a) === norm(b)
}

export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

/**
 * POST a form body with a per-action CSRF token in the header; the parsed
 * JSON, or an Error carrying `status`.
 */
export async function postForm(url, token, fields, signal) {
    const body = new FormData()
    for (const [k, v] of Object.entries(fields)) body.append(k, v)
    const response = await fetch(url, {
        method: "POST",
        body,
        signal,
        headers: { "X-CSRF-Token": token, Accept: "application/json" },
        credentials: "same-origin",
    })
    if (!response.ok) {
        const error = new Error(`HTTP ${response.status}`)
        error.status = response.status
        throw error
    }
    return response.json()
}
