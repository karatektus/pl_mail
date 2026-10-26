/**
 * The PKCS#12 PBE schemes (RFC 7292 Appendix C), for opening the .p12 files
 * that predate PBES2: what Windows' certificate export writes unless told
 * "AES256-SHA256", what older macOS and OpenSSL 1.x write, and what some CAs
 * still hand out. The key bag is usually 3DES, the certificates RC2-40.
 *
 * WebCrypto has none of it — not the key derivation of Appendix B, not DES,
 * not RC2 — and PKI.js stops at PBES2. So it is here by hand, and only the
 * decrypting half: plMail reads these files once, on import, and never writes
 * one (crypto/smime.js writes PBES2 with AES-256). How weak the ciphers are
 * matters little for that: they guarded the file on the person's disk, which
 * the person already has, and the certificates RC2-40 hides are public.
 *
 * Nothing here runs in constant time; nothing here needs to. The only secret
 * is the person's own file password, on their own machine.
 */

import { sha1 } from "@noble/hashes/sha1";

/** pkcs-12PbeIds: the cipher, its key length in bytes, RC2's effective key bits. */
const SCHEMES = Object.freeze({
    "1.2.840.113549.1.12.1.1": { cipher: "rc4", keyBytes: 16 },
    "1.2.840.113549.1.12.1.2": { cipher: "rc4", keyBytes: 5 },
    "1.2.840.113549.1.12.1.3": { cipher: "3des", keyBytes: 24 },
    "1.2.840.113549.1.12.1.4": { cipher: "3des", keyBytes: 16 },
    "1.2.840.113549.1.12.1.5": { cipher: "rc2", keyBytes: 16, effectiveBits: 128 },
    "1.2.840.113549.1.12.1.6": { cipher: "rc2", keyBytes: 5, effectiveBits: 40 },
});

/** The diversifiers of RFC 7292 B.3. */
const KEY_MATERIAL = 1;
const IV_MATERIAL = 2;

/**
 * Decrypts what a PKCS#12 PBE scheme locked.
 *
 * @param {string} algorithmId one of the pkcs-12PbeIds
 * @param {Uint8Array} salt from pkcs-12PbeParams
 * @param {number} iterations from pkcs-12PbeParams
 * @param {string} password
 * @param {Uint8Array} ciphertext
 * @returns {Uint8Array}
 * @throws {Error} unsupported_pbe, bad_padding (a wrong password, as a rule)
 */
export function decryptLegacyPbe(algorithmId, salt, iterations, password, ciphertext) {
    const scheme = SCHEMES[algorithmId];

    if (undefined === scheme) {
        throw new Error("unsupported_pbe");
    }

    const secret = bmpString(password);
    const key = deriveKey(secret, salt, iterations, KEY_MATERIAL, scheme.keyBytes);

    if ("rc4" === scheme.cipher) {
        return rc4(key, ciphertext);
    }

    const iv = deriveKey(secret, salt, iterations, IV_MATERIAL, 8);

    if ("3des" === scheme.cipher) {
        // Two-key 3DES is three-key 3DES with K3 = K1.
        const keys = 16 === key.length ? [key.subarray(0, 8), key.subarray(8, 16), key.subarray(0, 8)] : [key.subarray(0, 8), key.subarray(8, 16), key.subarray(16, 24)];
        const [first, second, third] = keys.map(desSubkeys);

        return unpad(cbcDecrypt(ciphertext, iv, (block) => desBlock(desBlock(desBlock(block, [...third].reverse()), second), [...first].reverse())));
    }

    const expanded = rc2Key(key, scheme.effectiveBits);

    return unpad(cbcDecrypt(ciphertext, iv, (block) => rc2DecryptBlock(block, expanded)));
}

// ── Key derivation (RFC 7292 Appendix B) ────────────────────────────────────

/** The password as a BMPString with its two-byte terminator, which is what Appendix B hashes. */
function bmpString(password) {
    const bytes = new Uint8Array(password.length * 2 + 2);
    const view = new DataView(bytes.buffer);

    for (let index = 0; index < password.length; index += 1) {
        view.setUint16(index * 2, password.charCodeAt(index), false);
    }

    return bytes;
}

/** $length bytes of key material of kind $id, with SHA-1: u = 20, v = 64. */
function deriveKey(password, salt, iterations, id, length) {
    const u = 20;
    const v = 64;
    const stretch = (bytes) => {
        const out = new Uint8Array(v * Math.ceil(bytes.length / v));

        for (let index = 0; index < out.length; index += 1) {
            out[index] = bytes[index % bytes.length];
        }

        return out;
    };

    const input = new Uint8Array([...stretch(salt), ...stretch(password)]);
    const out = new Uint8Array(Math.ceil(length / u) * u);

    for (let round = 0; round * u < length; round += 1) {
        let a = new Uint8Array([...new Uint8Array(v).fill(id), ...input]);

        for (let iteration = 0; iteration < iterations; iteration += 1) {
            a = sha1(a);
        }

        out.set(a, round * u);

        // I_j = (I_j + B + 1) mod 2^(8v) for every v-byte block of I, B being A repeated to v bytes.
        const b = stretch(a).subarray(0, v);

        for (let start = 0; start < input.length; start += v) {
            let carry = 1;

            for (let index = v - 1; index >= 0; index -= 1) {
                carry += input[start + index] + b[index];
                input[start + index] = carry & 0xff;
                carry >>= 8;
            }
        }
    }

    return out.subarray(0, length);
}

// ── CBC and padding ─────────────────────────────────────────────────────────

function cbcDecrypt(ciphertext, iv, decryptBlock) {
    if (0 === ciphertext.length || 0 !== ciphertext.length % 8) {
        throw new Error("bad_padding");
    }

    const out = new Uint8Array(ciphertext.length);
    let previous = iv;

    for (let start = 0; start < ciphertext.length; start += 8) {
        const block = ciphertext.subarray(start, start + 8);
        const plain = decryptBlock(block);

        for (let index = 0; index < 8; index += 1) {
            out[start + index] = plain[index] ^ previous[index];
        }

        previous = block;
    }

    return out;
}

function unpad(bytes) {
    const count = bytes[bytes.length - 1];

    if (count < 1 || count > 8 || bytes.subarray(bytes.length - count).some((byte) => byte !== count)) {
        throw new Error("bad_padding");
    }

    return bytes.slice(0, bytes.length - count);
}

// ── DES (FIPS 46-3) ─────────────────────────────────────────────────────────
//
// Bit by bit, as the standard writes it: a few kilobytes of key and
// certificate are all it ever sees.

const IP = [58, 50, 42, 34, 26, 18, 10, 2, 60, 52, 44, 36, 28, 20, 12, 4, 62, 54, 46, 38, 30, 22, 14, 6, 64, 56, 48, 40, 32, 24, 16, 8,
    57, 49, 41, 33, 25, 17, 9, 1, 59, 51, 43, 35, 27, 19, 11, 3, 61, 53, 45, 37, 29, 21, 13, 5, 63, 55, 47, 39, 31, 23, 15, 7];
const FP = IP.map((_, index) => IP.indexOf(index + 1) + 1);
const E = [32, 1, 2, 3, 4, 5, 4, 5, 6, 7, 8, 9, 8, 9, 10, 11, 12, 13, 12, 13, 14, 15, 16, 17,
    16, 17, 18, 19, 20, 21, 20, 21, 22, 23, 24, 25, 24, 25, 26, 27, 28, 29, 28, 29, 30, 31, 32, 1];
const P = [16, 7, 20, 21, 29, 12, 28, 17, 1, 15, 23, 26, 5, 18, 31, 10, 2, 8, 24, 14, 32, 27, 3, 9, 19, 13, 30, 6, 22, 11, 4, 25];
const PC1 = [57, 49, 41, 33, 25, 17, 9, 1, 58, 50, 42, 34, 26, 18, 10, 2, 59, 51, 43, 35, 27, 19, 11, 3, 60, 52, 44, 36,
    63, 55, 47, 39, 31, 23, 15, 7, 62, 54, 46, 38, 30, 22, 14, 6, 61, 53, 45, 37, 29, 21, 13, 5, 28, 20, 12, 4];
const PC2 = [14, 17, 11, 24, 1, 5, 3, 28, 15, 6, 21, 10, 23, 19, 12, 4, 26, 8, 16, 7, 27, 20, 13, 2,
    41, 52, 31, 37, 47, 55, 30, 40, 51, 45, 33, 48, 44, 49, 39, 56, 34, 53, 46, 42, 50, 36, 29, 32];
const SHIFTS = [1, 1, 2, 2, 2, 2, 2, 2, 1, 2, 2, 2, 2, 2, 2, 1];
const SBOXES = [
    [14, 4, 13, 1, 2, 15, 11, 8, 3, 10, 6, 12, 5, 9, 0, 7, 0, 15, 7, 4, 14, 2, 13, 1, 10, 6, 12, 11, 9, 5, 3, 8,
        4, 1, 14, 8, 13, 6, 2, 11, 15, 12, 9, 7, 3, 10, 5, 0, 15, 12, 8, 2, 4, 9, 1, 7, 5, 11, 3, 14, 10, 0, 6, 13],
    [15, 1, 8, 14, 6, 11, 3, 4, 9, 7, 2, 13, 12, 0, 5, 10, 3, 13, 4, 7, 15, 2, 8, 14, 12, 0, 1, 10, 6, 9, 11, 5,
        0, 14, 7, 11, 10, 4, 13, 1, 5, 8, 12, 6, 9, 3, 2, 15, 13, 8, 10, 1, 3, 15, 4, 2, 11, 6, 7, 12, 0, 5, 14, 9],
    [10, 0, 9, 14, 6, 3, 15, 5, 1, 13, 12, 7, 11, 4, 2, 8, 13, 7, 0, 9, 3, 4, 6, 10, 2, 8, 5, 14, 12, 11, 15, 1,
        13, 6, 4, 9, 8, 15, 3, 0, 11, 1, 2, 12, 5, 10, 14, 7, 1, 10, 13, 0, 6, 9, 8, 7, 4, 15, 14, 3, 11, 5, 2, 12],
    [7, 13, 14, 3, 0, 6, 9, 10, 1, 2, 8, 5, 11, 12, 4, 15, 13, 8, 11, 5, 6, 15, 0, 3, 4, 7, 2, 12, 1, 10, 14, 9,
        10, 6, 9, 0, 12, 11, 7, 13, 15, 1, 3, 14, 5, 2, 8, 4, 3, 15, 0, 6, 10, 1, 13, 8, 9, 4, 5, 11, 12, 7, 2, 14],
    [2, 12, 4, 1, 7, 10, 11, 6, 8, 5, 3, 15, 13, 0, 14, 9, 14, 11, 2, 12, 4, 7, 13, 1, 5, 0, 15, 10, 3, 9, 8, 6,
        4, 2, 1, 11, 10, 13, 7, 8, 15, 9, 12, 5, 6, 3, 0, 14, 11, 8, 12, 7, 1, 14, 2, 13, 6, 15, 0, 9, 10, 4, 5, 3],
    [12, 1, 10, 15, 9, 2, 6, 8, 0, 13, 3, 4, 14, 7, 5, 11, 10, 15, 4, 2, 7, 12, 9, 5, 6, 1, 13, 14, 0, 11, 3, 8,
        9, 14, 15, 5, 2, 8, 12, 3, 7, 0, 4, 10, 1, 13, 11, 6, 4, 3, 2, 12, 9, 5, 15, 10, 11, 14, 1, 7, 6, 0, 8, 13],
    [4, 11, 2, 14, 15, 0, 8, 13, 3, 12, 9, 7, 5, 10, 6, 1, 13, 0, 11, 7, 4, 9, 1, 10, 14, 3, 5, 12, 2, 15, 8, 6,
        1, 4, 11, 13, 12, 3, 7, 14, 10, 15, 6, 8, 0, 5, 9, 2, 6, 11, 13, 8, 1, 4, 10, 7, 9, 5, 0, 15, 14, 2, 3, 12],
    [13, 2, 8, 4, 6, 15, 11, 1, 10, 9, 3, 14, 5, 0, 12, 7, 1, 15, 13, 8, 10, 3, 7, 4, 12, 5, 6, 11, 0, 14, 9, 2,
        7, 11, 4, 1, 9, 12, 14, 2, 0, 6, 10, 13, 15, 3, 5, 8, 2, 1, 14, 7, 4, 10, 8, 13, 15, 12, 9, 0, 3, 5, 6, 11],
];

function bitsOf(bytes) {
    return Array.from({ length: bytes.length * 8 }, (_, index) => (bytes[index >> 3] >> (7 - (index & 7))) & 1);
}

function bytesOf(bits) {
    const bytes = new Uint8Array(bits.length / 8);

    bits.forEach((bit, index) => {
        bytes[index >> 3] |= bit << (7 - (index & 7));
    });

    return bytes;
}

function permute(bits, table) {
    return table.map((position) => bits[position - 1]);
}

/** The sixteen round keys of one DES key, in encryption order. */
function desSubkeys(key) {
    const permuted = permute(bitsOf(key), PC1);
    let c = permuted.slice(0, 28);
    let d = permuted.slice(28);

    return SHIFTS.map((shift) => {
        c = [...c.slice(shift), ...c.slice(0, shift)];
        d = [...d.slice(shift), ...d.slice(0, shift)];

        return permute([...c, ...d], PC2);
    });
}

/** One block through the sixteen rounds; reversed round keys decrypt. */
function desBlock(block, subkeys) {
    const permuted = permute(bitsOf(block), IP);
    let left = permuted.slice(0, 32);
    let right = permuted.slice(32);

    for (const subkey of subkeys) {
        const mixed = permute(right, E).map((bit, index) => bit ^ subkey[index]);
        const substituted = [];

        for (let box = 0; box < 8; box += 1) {
            const six = mixed.slice(box * 6, box * 6 + 6);
            const value = SBOXES[box][((six[0] << 1) | six[5]) * 16 + ((six[1] << 3) | (six[2] << 2) | (six[3] << 1) | six[4])];

            substituted.push((value >> 3) & 1, (value >> 2) & 1, (value >> 1) & 1, value & 1);
        }

        [left, right] = [right, permute(substituted, P).map((bit, index) => bit ^ left[index])];
    }

    return bytesOf(permute([...right, ...left], FP));
}

// ── RC2 (RFC 2268) ──────────────────────────────────────────────────────────

const PITABLE = Uint8Array.from((
    "d978f9c419ddb5ed28e9fd794aa0d89dc67e37832b76538e624c6488448bfba2179a59f587b34f1361456d8d09817d32bd8f40eb86b77b0bf09521225c6b4e82"
    + "54d66593ce60b21c7356c014a78cf1dc1275ca1f3bbee4d1423dd430a33cb6266fbf0eda4669075727f21d9bbc944303f811c7f690ef3ee706c3d52fc8661ed7"
    + "08e8eade8052eef784aa72ac354d6a2a961ad2715a1549744b9fd05e0418a4ecc2e0416e0f51cbcc2491af50a1f47039997c3a8523b8b47afc02365b25559731"
    + "2d5dfa98e38a92ae05df2910676cbac9d300e6cfe19ea82c6316013f58e289a90d38341bab33ffb0bb480c5fb9b1cd2ec5f3db47e5a59c770aa62068fe7fc1ad"
).match(/../g), (pair) => parseInt(pair, 16));

/** The sixty-four 16-bit words of an RC2 key with $bits effective bits. */
function rc2Key(key, bits) {
    const l = new Uint8Array(128);
    const t8 = Math.ceil(bits / 8);
    const tm = 0xff >> (8 * t8 - bits);

    l.set(key);

    for (let index = key.length; index < 128; index += 1) {
        l[index] = PITABLE[(l[index - 1] + l[index - key.length]) & 0xff];
    }

    l[128 - t8] = PITABLE[l[128 - t8] & tm];

    for (let index = 127 - t8; index >= 0; index -= 1) {
        l[index] = PITABLE[l[index + 1] ^ l[index + t8]];
    }

    return Array.from({ length: 64 }, (_, index) => l[2 * index] | (l[2 * index + 1] << 8));
}

function rc2DecryptBlock(block, k) {
    const r = [0, 1, 2, 3].map((index) => block[2 * index] | (block[2 * index + 1] << 8));
    const rotations = [1, 2, 3, 5];
    let j = 63;

    const mix = () => {
        for (let i = 3; i >= 0; i -= 1) {
            r[i] = ((r[i] >>> rotations[i]) | (r[i] << (16 - rotations[i]))) & 0xffff;
            r[i] = (r[i] - k[j] - (r[(i + 3) % 4] & r[(i + 2) % 4]) - (~r[(i + 3) % 4] & r[(i + 1) % 4])) & 0xffff;
            j -= 1;
        }
    };
    const mash = () => {
        for (let i = 3; i >= 0; i -= 1) {
            r[i] = (r[i] - k[r[(i + 3) % 4] & 63]) & 0xffff;
        }
    };

    // Encryption's five mixing rounds, a mash, six, a mash, five — backwards.
    for (const step of [...Array(5).fill(mix), mash, ...Array(6).fill(mix), mash, ...Array(5).fill(mix)]) {
        step();
    }

    return Uint8Array.from(r.flatMap((word) => [word & 0xff, word >> 8]));
}

// ── RC4 ─────────────────────────────────────────────────────────────────────

function rc4(key, input) {
    const s = Uint8Array.from({ length: 256 }, (_, index) => index);
    const out = new Uint8Array(input.length);
    let j = 0;

    for (let i = 0; i < 256; i += 1) {
        j = (j + s[i] + key[i % key.length]) & 0xff;
        [s[i], s[j]] = [s[j], s[i]];
    }

    j = 0;

    for (let index = 0, i = 0; index < input.length; index += 1) {
        i = (i + 1) & 0xff;
        j = (j + s[i]) & 0xff;
        [s[i], s[j]] = [s[j], s[i]];
        out[index] = input[index] ^ s[(s[i] + s[j]) & 0xff];
    }

    return out;
}
