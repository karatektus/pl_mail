import { hkdf } from "./aead.js";

/**
 * Twelve words that open a person's keyring when the password cannot.
 *
 * BIP39, English wordlist, 128 bits: the format people already know from
 * every wallet and from Proton, which checks itself — a mistyped word fails
 * the checksum instead of quietly deriving a different key. The words are
 * shown once and never stored; the server keeps only the user key wrapped
 * under what they derive.
 *
 *     phraseKey = HKDF-SHA256(entropy, info = "plmail/recovery-phrase")
 *
 * HKDF and not BIP39's own seed function: that is PBKDF2 with 2048 rounds and
 * exists to stretch a passphrase, and 128 bits of entropy need no stretching.
 */

/**
 * @returns {Promise<string>} twelve words, space-separated
 */
export async function newPhrase() {
    const [{ generateMnemonic }, { wordlist }] = await libraries();

    return generateMnemonic(wordlist, 128);
}

/**
 * The key a phrase opens copies with, or null when it is not a valid phrase.
 *
 * Forgiving about what a person types: case, extra spaces and line breaks.
 *
 * @param {string} words
 * @returns {Promise<Uint8Array|null>}
 */
export async function phraseKey(words) {
    const [{ mnemonicToEntropy, validateMnemonic }, { wordlist }] = await libraries();
    const normalised = String(words).toLowerCase().trim().split(/\s+/).join(" ");

    if (false === validateMnemonic(normalised, wordlist)) {
        return null;
    }

    return hkdf(mnemonicToEntropy(normalised, wordlist), "plmail/recovery-phrase");
}

function libraries() {
    return Promise.all([import("@scure/bip39"), import("@scure/bip39/wordlists/english")]);
}
