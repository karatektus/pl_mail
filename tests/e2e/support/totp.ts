import { createHmac } from "node:crypto";

/**
 * RFC 6238 TOTP, in about thirty lines.
 *
 * Hand-rolled rather than pulled in as a dependency: the e2e suite needs to
 * behave like an authenticator app for exactly one spec, and the algorithm is
 * small enough that a package would be more code to audit than this is to read.
 *
 * The parameters are fixed to the ones plMail issues — SHA-1, 30 seconds, 6
 * digits — which is deliberate. If the app ever starts issuing something else,
 * this stops agreeing with it and the spec fails, which is the correct outcome:
 * Google Authenticator assumes these three values and would break too.
 */

const PERIOD = 30;
const DIGITS = 6;

/** Decode the base32 (RFC 4648, no padding) secret an otpauth:// URI carries. */
function decodeBase32(secret: string): Buffer {
    const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    const clean = secret.toUpperCase().replace(/[^A-Z2-7]/g, "");

    let bits = "";

    for (const char of clean) {
        const value = alphabet.indexOf(char);

        if (value < 0) {
            throw new Error(`"${char}" is not a base32 character`);
        }

        bits += value.toString(2).padStart(5, "0");
    }

    const bytes: number[] = [];

    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }

    return Buffer.from(bytes);
}

/**
 * The code an authenticator app would be showing for this secret right now.
 *
 * `atSecond` exists so a caller can ask for the code in the next window — see
 * secondsUntilNextWindow().
 */
export function totp(secret: string, atSecond: number = Date.now() / 1000): string {
    const counter = Math.floor(atSecond / PERIOD);

    const message = Buffer.alloc(8);
    message.writeUInt32BE(Math.floor(counter / 2 ** 32), 0);
    message.writeUInt32BE(counter >>> 0, 4);

    const digest = createHmac("sha1", decodeBase32(secret)).update(message).digest();

    // Dynamic truncation: the low nibble of the last byte picks where to read.
    const offset = digest[digest.length - 1] & 0x0f;
    const binary =
        ((digest[offset] & 0x7f) << 24) |
        ((digest[offset + 1] & 0xff) << 16) |
        ((digest[offset + 2] & 0xff) << 8) |
        (digest[offset + 3] & 0xff);

    return (binary % 10 ** DIGITS).toString().padStart(DIGITS, "0");
}

/**
 * How long until the current code rolls over.
 *
 * Used to avoid the one flaky shape this spec can have: generating a code with
 * a fraction of a second left in its window, then submitting it after the
 * window has passed. plMail allows 15 seconds of leeway either side, so this
 * only has to wait when there is less than that left.
 */
export function secondsUntilNextWindow(): number {
    return PERIOD - (Math.floor(Date.now() / 1000) % PERIOD);
}
