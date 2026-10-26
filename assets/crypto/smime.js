/**
 * S/MIME in the browser: a person's own certificate from a PKCS#12 file, and
 * CMS (RFC 5652) for the mail it signs and encrypts.
 *
 * PKI.js reads and writes the structures — PKCS#12, Certificate, SignedData,
 * EnvelopedData — and WebCrypto does what it can: SHA-256, RSA signatures,
 * RSA-OAEP, the PBES2 of a PKCS#12 file. Two things it cannot do are here by
 * hand, because the mail programs S/MIME is for depend on them:
 *
 *   - RSA key transport with PKCS#1 v1.5 padding (RFC 8017 §7.2). It is what
 *     Outlook and Thunderbird encrypt with by default and the one padding all
 *     of them decrypt; WebCrypto offers only OAEP for encryption. So the
 *     content key is wrapped and unwrapped with BigInt arithmetic below —
 *     blinded, so the time a decryption takes says nothing about the key.
 *     OAEP is still read (WebCrypto), for the senders that use it.
 *   - AES-CBC, through @noble/ciphers, because WebCrypto in Chrome has no
 *     192-bit AES and senders choose the length. CBC rather than GCM
 *     (AuthEnvelopedData, RFC 5083), because Outlook does not read GCM.
 *
 * What goes out is the most widely read form: AES-256-CBC content, RSA
 * PKCS#1 v1.5 key transport to every recipient and the sender, recipients
 * named by issuer and serial number; signatures RSA with SHA-256, detached,
 * with the signer's certificate and its chain inside.
 *
 * Nothing here talks to the server. Keys come in as PKCS#8 bytes the keyring
 * unwrapped (crypto/keyring.js) and are dropped when the call returns.
 */

import { base64ToBytes, bytesToBase64 } from "./mime.js";

const OID = Object.freeze({
    data: "1.2.840.113549.1.7.1",
    signedData: "1.2.840.113549.1.7.2",
    envelopedData: "1.2.840.113549.1.7.3",
    rsaEncryption: "1.2.840.113549.1.1.1",
    rsaOaep: "1.2.840.113549.1.1.7",
    sha1: "1.3.14.3.2.26",
    sha256: "2.16.840.1.101.3.4.2.1",
    sha384: "2.16.840.1.101.3.4.2.2",
    sha512: "2.16.840.1.101.3.4.2.3",
    aes128Cbc: "2.16.840.1.101.3.4.1.2",
    aes192Cbc: "2.16.840.1.101.3.4.1.22",
    aes256Cbc: "2.16.840.1.101.3.4.1.42",
    contentType: "1.2.840.113549.1.9.3",
    messageDigest: "1.2.840.113549.1.9.4",
    signingTime: "1.2.840.113549.1.9.5",
    smimeCapabilities: "1.2.840.113549.1.9.15",
    emailAddress: "1.2.840.113549.1.9.1",
    commonName: "2.5.4.3",
    subjectAltName: "2.5.29.17",
    keyUsage: "2.5.29.15",
    pkcs8ShroudedKeyBag: "1.2.840.113549.1.12.10.1.2",
    certBag: "1.2.840.113549.1.12.10.1.3",
    x509Certificate: "1.2.840.113549.1.9.22.1",
    localKeyId: "1.2.840.113549.1.9.21",
    friendlyName: "1.2.840.113549.1.9.20",
});

const AES_KEY_BYTES = { [OID.aes128Cbc]: 16, [OID.aes192Cbc]: 24, [OID.aes256Cbc]: 32 };
const HASHES = { [OID.sha1]: "SHA-1", [OID.sha256]: "SHA-256", [OID.sha384]: "SHA-384", [OID.sha512]: "SHA-512" };

/** What a written PKCS#12 file is locked with: OpenSSL 3's defaults, which Windows 10+ and macOS read. */
const PKCS12_ITERATIONS = 100000;

const encoder = new TextEncoder();

// ── Certificates ────────────────────────────────────────────────────────────

/**
 * What the composer, the reading pane and the settings page need to know
 * about a certificate. Mirrors App\Service\Crypto\CertificateReader.
 *
 * @param {string|Uint8Array} certificate PEM or DER
 * @returns {Promise<{pem: string, fingerprint: string, addresses: string[], subject: string, issuer: string, notBefore: Date, notAfter: Date, rsa: boolean}>}
 */
export async function describeCertificate(certificate) {
    const { pkijs } = await libraries();
    const der = typeof certificate === "string" ? pemToDer(certificate) : certificate;
    const parsed = pkijs.Certificate.fromBER(toBuffer(der));

    return {
        pem: derToPem(der),
        fingerprint: await fingerprintOf(der),
        addresses: addressesOf(parsed),
        subject: nameOf(parsed.subject),
        issuer: nameOf(parsed.issuer),
        notBefore: parsed.notBefore.value,
        notAfter: parsed.notAfter.value,
        rsa: OID.rsaEncryption === parsed.subjectPublicKeyInfo.algorithm.algorithmId,
    };
}

/** Lower-case hex SHA-256 of a certificate's DER: what plMail names it by. */
export async function fingerprintOf(der) {
    const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", toBuffer(der)));

    return Array.from(digest, (byte) => byte.toString(16).padStart(2, "0")).join("");
}

/** Every certificate in a PEM bundle, as DER. */
export function pemBlocks(pem) {
    return [...String(pem ?? "").matchAll(/-----BEGIN CERTIFICATE-----([A-Za-z0-9+/=\s]+)-----END CERTIFICATE-----/g)]
        .map((match) => base64ToBytes(match[1]));
}

export function pemToDer(pem) {
    const [first] = pemBlocks(pem);

    if (undefined === first) {
        throw new Error("not_a_certificate");
    }

    return first;
}

export function derToPem(der) {
    const lines = bytesToBase64(der).match(/.{1,64}/g) ?? [];

    return `-----BEGIN CERTIFICATE-----\n${lines.join("\n")}\n-----END CERTIFICATE-----\n`;
}

// ── PKCS#12 ─────────────────────────────────────────────────────────────────

/**
 * Opens a PKCS#12 file (.p12, .pfx): the certificate whose key is in it, the
 * other certificates as its chain, and the key as PKCS#8.
 *
 * Read with the integrity check on: a wrong password fails there, before
 * anything is decrypted. PBES2 (AES), what OpenSSL 3 writes, through PKI.js;
 * the older "PKCS#12 PBE" schemes (3DES, RC2, RC4) — what Windows' export
 * writes by default, and some CAs' — through crypto/pkcs12_legacy.js, which
 * is fetched only for such a file. What neither opens (a PBES2 file with a
 * cipher other than AES) is refused with a reason the settings page explains.
 *
 * @param {Uint8Array} bytes
 * @param {string} password
 * @returns {Promise<{certificate: string, chain: string, pkcs8: Uint8Array}>}
 * @throws {Error} not_pkcs12, wrong_password, legacy_pkcs12, no_key, no_certificate, not_rsa
 */
export async function readPkcs12(bytes, password) {
    const { pkijs } = await libraries();
    const secret = toBuffer(encoder.encode(password));
    const engine = withLegacyPbe(pkijs.getCrypto(true), password);
    const reason = (error) => (/unsupported|unknown|not supported/i.test(String(error?.message)) ? "legacy_pkcs12" : "wrong_password");
    let pfx;

    try {
        pfx = pkijs.PFX.fromBER(toBuffer(bytes));
    } catch {
        throw new Error("not_pkcs12");
    }

    try {
        await pfx.parseInternalValues({ password: secret, checkIntegrity: true });
    } catch {
        throw new Error("wrong_password");
    }

    const safe = pfx.parsedValue?.authenticatedSafe;

    if (undefined === safe) {
        throw new Error("not_pkcs12");
    }

    try {
        await safe.parseInternalValues({ safeContents: safe.safeContents.map(() => ({ password: secret })) }, engine);
    } catch (error) {
        throw new Error(reason(error));
    }

    const keys = [];
    const certificates = [];

    for (const content of safe.parsedValue.safeContents) {
        for (const bag of content.value.safeBags) {
            if (OID.pkcs8ShroudedKeyBag === bag.bagId) {
                await bag.bagValue.parseInternalValues({ password: secret }, engine).catch((error) => {
                    throw new Error(reason(error));
                });
                keys.push(bag.bagValue.parsedValue);
            } else if ("1.2.840.113549.1.12.10.1.1" === bag.bagId) {
                keys.push(bag.bagValue);
            } else if (OID.certBag === bag.bagId && OID.x509Certificate === bag.bagValue.certId) {
                certificates.push(bag.bagValue.parsedValue);
            }
        }
    }

    const key = keys[0];

    if (undefined === key) {
        throw new Error("no_key");
    }

    if (OID.rsaEncryption !== key.privateKeyAlgorithm.algorithmId) {
        throw new Error("not_rsa");
    }

    const modulus = integerHex(key.parsedKey.modulus);
    const own = certificates.find((certificate) => integerHex(certificate.subjectPublicKeyInfo.parsedKey?.modulus) === modulus);

    if (undefined === own) {
        throw new Error("no_certificate");
    }

    return {
        certificate: derToPem(new Uint8Array(own.toSchema().toBER(false))),
        chain: certificates.filter((certificate) => certificate !== own).map((certificate) => derToPem(new Uint8Array(certificate.toSchema().toBER(false)))).join(""),
        pkcs8: new Uint8Array(key.toSchema().toBER(false)),
    };
}

/**
 * A PKCS#12 file of a certificate, its chain and its key, locked with
 * $password: PBES2 with AES-256-CBC and PBKDF2-SHA-256 for the key, an
 * HMAC-SHA-256 over the whole — the shape `openssl pkcs12 -export` writes.
 *
 * @returns {Promise<Uint8Array>}
 */
export async function writePkcs12({ certificate, chain = "", pkcs8, password }) {
    const { asn1js, pkijs } = await libraries();
    const secret = toBuffer(encoder.encode(password));
    const own = pkijs.Certificate.fromBER(toBuffer(pemToDer(certificate)));
    // The SHA-1 of the public key, which is what OpenSSL ties a key and its
    // certificate together with.
    const keyId = await crypto.subtle.digest("SHA-1", toBuffer(new Uint8Array(own.subjectPublicKeyInfo.subjectPublicKey.valueBlock.valueHexView)));
    const localKeyId = () => new pkijs.Attribute({ type: OID.localKeyId, values: [new asn1js.OctetString({ valueHex: keyId })] });
    const encryption = { password: secret, contentEncryptionAlgorithm: { name: "AES-CBC", length: 256 }, hmacHashAlgorithm: "SHA-256", iterationCount: PKCS12_ITERATIONS };

    const certificateBags = [own, ...pemBlocks(chain).map((der) => pkijs.Certificate.fromBER(toBuffer(der)))].map((parsed, index) => new pkijs.SafeBag({
        bagId: OID.certBag,
        bagValue: new pkijs.CertBag({ parsedValue: parsed }),
        bagAttributes: 0 === index ? [localKeyId()] : undefined,
    }));

    const keyBag = new pkijs.SafeBag({
        bagId: OID.pkcs8ShroudedKeyBag,
        bagValue: new pkijs.PKCS8ShroudedKeyBag({ parsedValue: pkijs.PrivateKeyInfo.fromBER(toBuffer(pkcs8)) }),
        bagAttributes: [localKeyId()],
    });

    const pfx = new pkijs.PFX({
        parsedValue: {
            integrityMode: 0,
            authenticatedSafe: new pkijs.AuthenticatedSafe({
                parsedValue: {
                    safeContents: [
                        { privacyMode: 1, value: new pkijs.SafeContents({ safeBags: certificateBags }) },
                        { privacyMode: 0, value: new pkijs.SafeContents({ safeBags: [keyBag] }) },
                    ],
                },
            }),
        },
    });

    await keyBag.bagValue.makeInternalValues(encryption);
    await pfx.parsedValue.authenticatedSafe.makeInternalValues({ safeContents: [encryption, {}] });
    await pfx.makeInternalValues({ password: secret, iterations: PKCS12_ITERATIONS, pbkdf2HashAlgorithm: "SHA-256", hmacHashAlgorithm: "SHA-256" });

    return new Uint8Array(pfx.toSchema().toBER(false));
}

// ── Enveloped data ──────────────────────────────────────────────────────────

/**
 * Encrypts $content (the MIME entity) to every certificate given.
 *
 * @param {Uint8Array} content
 * @param {string[]} certificates PEM, one per recipient, the sender's included
 * @returns {Promise<Uint8Array>} a DER ContentInfo holding EnvelopedData
 */
export async function envelope(content, certificates) {
    const { asn1js, pkijs } = await libraries();
    const { cbc } = await import("@noble/ciphers/aes");
    const contentKey = randomBytes(32);
    const iv = randomBytes(16);
    const ciphertext = cbc(contentKey, iv).encrypt(content);
    const recipientInfos = [];

    for (const pem of certificates) {
        const certificate = pkijs.Certificate.fromBER(toBuffer(pemToDer(pem)));
        const publicKey = certificate.subjectPublicKeyInfo.parsedKey;

        if (OID.rsaEncryption !== certificate.subjectPublicKeyInfo.algorithm.algorithmId || undefined === publicKey) {
            throw new Error("not_rsa");
        }

        recipientInfos.push(new pkijs.RecipientInfo({
            variant: 1,
            value: new pkijs.KeyTransRecipientInfo({
                version: 0,
                rid: new pkijs.IssuerAndSerialNumber({ issuer: certificate.issuer, serialNumber: certificate.serialNumber }),
                keyEncryptionAlgorithm: new pkijs.AlgorithmIdentifier({ algorithmId: OID.rsaEncryption, algorithmParams: new asn1js.Null() }),
                encryptedKey: new asn1js.OctetString({ valueHex: toBuffer(rsaEncryptPkcs1(contentKey, integerBig(publicKey.modulus), integerBig(publicKey.publicExponent))) }),
            }),
        }));
    }

    const enveloped = new pkijs.EnvelopedData({
        version: 0,
        recipientInfos,
        encryptedContentInfo: new pkijs.EncryptedContentInfo({
            contentType: OID.data,
            contentEncryptionAlgorithm: new pkijs.AlgorithmIdentifier({ algorithmId: OID.aes256Cbc, algorithmParams: new asn1js.OctetString({ valueHex: toBuffer(iv) }) }),
            encryptedContent: new asn1js.OctetString({ valueHex: toBuffer(ciphertext) }),
        }),
    });

    return contentInfo(OID.envelopedData, enveloped.toSchema());
}

/**
 * Opens EnvelopedData with whichever of the keys it was encrypted to.
 *
 * @param {Uint8Array} der a ContentInfo holding EnvelopedData
 * @param {Array<{certificate: string, pkcs8: Uint8Array}>} keys
 * @returns {Promise<Uint8Array>} the content: a MIME entity
 * @throws {Error} not_enveloped, no_key, unsupported_cipher
 */
export async function openEnvelope(der, keys) {
    const { pkijs } = await libraries();
    let enveloped;

    try {
        const info = pkijs.ContentInfo.fromBER(toBuffer(der));

        if (OID.envelopedData !== info.contentType) {
            throw new Error("not_enveloped");
        }

        enveloped = new pkijs.EnvelopedData({ schema: info.content });
    } catch {
        throw new Error("not_enveloped");
    }

    const algorithm = enveloped.encryptedContentInfo.contentEncryptionAlgorithm;
    const keyLength = AES_KEY_BYTES[algorithm.algorithmId];

    if (undefined === keyLength) {
        throw new Error("unsupported_cipher");
    }

    const iv = new Uint8Array(algorithm.algorithmParams.valueBlock.valueHexView);
    const ciphertext = new Uint8Array(enveloped.encryptedContentInfo.getEncryptedContent());
    const transports = enveloped.recipientInfos.filter((info) => 1 === info.variant).map((info) => info.value);
    const prepared = await Promise.all(keys.map(async (key) => ({ ...key, parsed: pkijs.Certificate.fromBER(toBuffer(pemToDer(key.certificate))) })));

    // Named recipients first; then every key against every recipient, for a
    // message that names them by a key identifier plMail does not compare,
    // or was sent to a certificate since renewed with the same key.
    const attempts = [];

    for (const transport of transports) {
        for (const key of prepared) {
            if (true === names(transport.rid, key.parsed)) {
                attempts.unshift([transport, key]);
            } else {
                attempts.push([transport, key]);
            }
        }
    }

    const { cbc } = await import("@noble/ciphers/aes");

    for (const [transport, key] of attempts) {
        try {
            const contentKey = await unwrapContentKey(transport, key.pkcs8);

            if (keyLength !== contentKey.length) {
                continue;
            }

            return cbc(contentKey, iv).decrypt(ciphertext);
        } catch {
            // Not this key; padding that does not check out is the usual way
            // of saying so.
        }
    }

    throw new Error("no_key");
}

// ── Signed data ─────────────────────────────────────────────────────────────

/**
 * A detached signature over $content, as DER SignedData: RSA with SHA-256,
 * the signing time and the S/MIME capabilities as signed attributes, the
 * signer's certificate and chain inside.
 *
 * @returns {Promise<Uint8Array>}
 */
export async function sign(content, { certificate, chain = "", pkcs8 }) {
    const { asn1js, pkijs } = await libraries();
    const own = pkijs.Certificate.fromBER(toBuffer(pemToDer(certificate)));
    const others = pemBlocks(chain).map((der) => pkijs.Certificate.fromBER(toBuffer(der)));
    const digest = await crypto.subtle.digest("SHA-256", toBuffer(content));
    const privateKey = await crypto.subtle.importKey("pkcs8", toBuffer(pkcs8), { name: "RSASSA-PKCS1-v1_5", hash: "SHA-256" }, false, ["sign"]);

    const signed = new pkijs.SignedData({
        version: 1,
        encapContentInfo: new pkijs.EncapsulatedContentInfo({ eContentType: OID.data }),
        signerInfos: [new pkijs.SignerInfo({
            version: 1,
            sid: new pkijs.IssuerAndSerialNumber({ issuer: own.issuer, serialNumber: own.serialNumber }),
            signedAttrs: new pkijs.SignedAndUnsignedAttributes({
                type: 0,
                attributes: [
                    new pkijs.Attribute({ type: OID.contentType, values: [new asn1js.ObjectIdentifier({ value: OID.data })] }),
                    new pkijs.Attribute({ type: OID.signingTime, values: [new asn1js.UTCTime({ valueDate: new Date() })] }),
                    new pkijs.Attribute({ type: OID.messageDigest, values: [new asn1js.OctetString({ valueHex: digest })] }),
                    new pkijs.Attribute({ type: OID.smimeCapabilities, values: [capabilities(asn1js)] }),
                ],
            }),
        })],
        certificates: [own, ...others],
    });

    await signed.sign(privateKey, 0, "SHA-256");

    return contentInfo(OID.signedData, signed.toSchema(true));
}

/**
 * Checks SignedData: that the signature is intact, over $content when it is
 * detached or over what it encapsulates when it is not.
 *
 * Only the signature: whether anybody vouches for the certificate is the
 * server's question (ContactKeyController::smimeSigner), which has the
 * system's authorities. What comes back is enough to ask it.
 *
 * @param {Uint8Array} der a ContentInfo holding SignedData
 * @param {?Uint8Array} content the signed bytes, for a detached signature
 * @returns {Promise<{intact: boolean, certificate: ?string, chain: string, content: ?Uint8Array}>}
 *          `content` is the encapsulated content of an opaque signature
 */
export async function verify(der, content = null) {
    const { pkijs } = await libraries();
    const info = pkijs.ContentInfo.fromBER(toBuffer(der));

    if (OID.signedData !== info.contentType) {
        throw new Error("not_signed");
    }

    const signed = new pkijs.SignedData({ schema: info.content });
    const encapsulated = signed.encapContentInfo.eContent;
    const inside = undefined === encapsulated ? null : octets(encapsulated);
    let result;

    try {
        result = await signed.verify({ signer: 0, data: null === content ? undefined : toBuffer(content), checkChain: false, extendedMode: true });
    } catch (error) {
        result = error;
    }

    const signer = result?.signerCertificate ?? null;
    const certificates = (signed.certificates ?? []).filter((certificate) => certificate instanceof pkijs.Certificate);

    return {
        intact: true === result?.signatureVerified,
        certificate: null === signer ? null : derToPem(new Uint8Array(signer.toSchema().toBER(false))),
        chain: certificates.filter((certificate) => certificate !== signer).map((certificate) => derToPem(new Uint8Array(certificate.toSchema().toBER(false)))).join(""),
        content: inside,
    };
}

// ── Private ─────────────────────────────────────────────────────────────────

let loaded = null;

/**
 * PKI.js and asn1js, loaded on first use: most pages never need them, and
 * PKI.js is a third of a megabyte.
 */
async function libraries() {
    loaded ??= Promise.all([import("asn1js"), import("pkijs")]).then(([asn1js, pkijs]) => ({ asn1js, pkijs }));

    return loaded;
}

/**
 * PKI.js's crypto engine, which also opens what a PKCS#12 PBE scheme
 * (pkcs-12PbeIds, 1.2.840.113549.1.12.1.*) locked. Handed to the one
 * readPkcs12 call rather than set globally; crypto/pkcs12_legacy.js is
 * imported only when a bag actually uses such a scheme.
 */
function withLegacyPbe(engine, password) {
    return Object.create(engine, {
        decryptEncryptedContentInfo: {
            async value(parameters) {
                const info = parameters.encryptedContentInfo;
                const algorithm = info.contentEncryptionAlgorithm;

                if (!algorithm.algorithmId.startsWith("1.2.840.113549.1.12.1.")) {
                    return engine.decryptEncryptedContentInfo(parameters);
                }

                const { decryptLegacyPbe } = await import("./pkcs12_legacy.js");
                const [salt, iterations] = algorithm.algorithmParams.valueBlock.value;

                return toBuffer(decryptLegacyPbe(
                    algorithm.algorithmId,
                    new Uint8Array(salt.valueBlock.valueHexView),
                    iterations.valueBlock.valueDec,
                    password,
                    new Uint8Array(info.getEncryptedContent()),
                ));
            },
        },
    });
}

async function contentInfo(type, content) {
    const { pkijs } = await libraries();

    return new Uint8Array(new pkijs.ContentInfo({ contentType: type, content }).toSchema().toBER(false));
}

/**
 * The content key of one KeyTransRecipientInfo, unwrapped with $pkcs8.
 */
async function unwrapContentKey(transport, pkcs8) {
    const algorithm = transport.keyEncryptionAlgorithm;
    const wrapped = new Uint8Array(transport.encryptedKey.valueBlock.valueHexView);

    if (OID.rsaEncryption === algorithm.algorithmId) {
        const { pkijs } = await libraries();
        const key = pkijs.PrivateKeyInfo.fromBER(toBuffer(pkcs8)).parsedKey;

        return rsaDecryptPkcs1(wrapped, key);
    }

    if (OID.rsaOaep === algorithm.algorithmId) {
        const { pkijs } = await libraries();
        const params = undefined === algorithm.algorithmParams ? null : new pkijs.RSAESOAEPParams({ schema: algorithm.algorithmParams });
        const hash = HASHES[params?.hashAlgorithm?.algorithmId ?? OID.sha1] ?? "SHA-1";
        const key = await crypto.subtle.importKey("pkcs8", toBuffer(pkcs8), { name: "RSA-OAEP", hash }, false, ["decrypt"]);

        return new Uint8Array(await crypto.subtle.decrypt({ name: "RSA-OAEP" }, key, toBuffer(wrapped)));
    }

    throw new Error("unsupported_key_transport");
}

/** Whether a RecipientIdentifier names this certificate. */
function names(rid, certificate) {
    if (undefined !== rid?.serialNumber && undefined !== rid?.issuer) {
        return integerHex(rid.serialNumber) === integerHex(certificate.serialNumber)
            && hex(rid.issuer.toSchema().toBER(false)) === hex(certificate.issuer.toSchema().toBER(false));
    }

    const identifier = rid?.valueBlock?.valueHexView;
    const extension = (certificate.extensions ?? []).find((candidate) => "2.5.29.14" === candidate.extnID);

    return undefined !== identifier && undefined !== extension
        && hex(identifier) === hex(extension.parsedValue?.valueBlock?.valueHexView ?? new Uint8Array());
}

/**
 * The S/MIME capabilities a signature announces (RFC 8551 §2.5.2): what
 * this sender can read, strongest first, so a recipient's reply is
 * encrypted with something plMail opens.
 */
function capabilities(asn1js) {
    const capability = (oid) => new asn1js.Sequence({ value: [new asn1js.ObjectIdentifier({ value: oid })] });

    return new asn1js.Sequence({ value: [capability(OID.aes256Cbc), capability(OID.aes192Cbc), capability(OID.aes128Cbc), capability(OID.rsaEncryption)] });
}

// ── RSA, PKCS#1 v1.5 key transport ──────────────────────────────────────────

/**
 * RSAES-PKCS1-v1_5 encryption (RFC 8017 §7.2.1) of a content key.
 */
function rsaEncryptPkcs1(message, modulus, exponent) {
    const length = byteLength(modulus);
    const paddingLength = length - message.length - 3;

    if (paddingLength < 8) {
        throw new Error("key_too_small");
    }

    const padded = new Uint8Array(length);
    const padding = nonZeroBytes(paddingLength);

    padded[1] = 0x02;
    padded.set(padding, 2);
    padded.set(message, 3 + paddingLength);

    return bigToBytes(modPow(bytesToBig(padded), exponent, modulus), length);
}

/**
 * RSAES-PKCS1-v1_5 decryption (RFC 8017 §7.2.2), with the private key from
 * a PKI.js RSAPrivateKey. Blinded: the ciphertext is multiplied by r^e
 * before the private operation and the result by r^-1 after, so its timing
 * does not depend on anything a sender chose.
 */
function rsaDecryptPkcs1(ciphertext, key) {
    const modulus = integerBig(key.modulus);
    const exponent = integerBig(key.publicExponent);
    const length = byteLength(modulus);
    const value = bytesToBig(ciphertext);

    if (ciphertext.length !== length || value >= modulus) {
        throw new Error("decryption_error");
    }

    let blind;

    do {
        blind = bytesToBig(randomBytes(length)) % modulus;
    } while (blind < 2n || 1n !== gcd(blind, modulus));

    const blinded = (value * modPow(blind, exponent, modulus)) % modulus;
    const opened = (crt(blinded, key) * modInverse(blind, modulus)) % modulus;
    const padded = bigToBytes(opened, length);

    let separator = -1;

    for (let index = 2; index < padded.length; index += 1) {
        if (0 === padded[index]) {
            separator = index;
            break;
        }
    }

    if (0x00 !== padded[0] || 0x02 !== padded[1] || separator < 10) {
        throw new Error("decryption_error");
    }

    return padded.slice(separator + 1);
}

/** The private operation, through the Chinese remainder theorem. */
function crt(value, key) {
    const p = integerBig(key.prime1);
    const q = integerBig(key.prime2);
    const m1 = modPow(value % p, integerBig(key.exponent1), p);
    const m2 = modPow(value % q, integerBig(key.exponent2), q);
    const h = (integerBig(key.coefficient) * ((m1 - m2) % p + p)) % p;

    return m2 + h * q;
}

function modPow(base, exponent, modulus) {
    let result = 1n;
    let factor = base % modulus;
    let rest = exponent;

    while (rest > 0n) {
        if (1n === (rest & 1n)) {
            result = (result * factor) % modulus;
        }

        factor = (factor * factor) % modulus;
        rest >>= 1n;
    }

    return result;
}

function modInverse(value, modulus) {
    let [a, b, x, y] = [value % modulus, modulus, 1n, 0n];

    while (0n !== b) {
        const quotient = a / b;

        [a, b] = [b, a - quotient * b];
        [x, y] = [y, x - quotient * y];
    }

    return ((x % modulus) + modulus) % modulus;
}

function gcd(a, b) {
    while (0n !== b) {
        [a, b] = [b, a % b];
    }

    return a;
}

// ── Bytes ───────────────────────────────────────────────────────────────────

function integerBig(integer) {
    return bytesToBig(new Uint8Array(integer.valueBlock.valueHexView));
}

function integerHex(integer) {
    return undefined === integer ? null : bytesToBig(new Uint8Array(integer.valueBlock.valueHexView)).toString(16);
}

function bytesToBig(bytes) {
    return 0 === bytes.length ? 0n : BigInt(`0x${hex(bytes)}`);
}

function bigToBytes(value, length) {
    const text = value.toString(16).padStart(length * 2, "0");
    const bytes = new Uint8Array(length);

    for (let index = 0; index < length; index += 1) {
        bytes[index] = parseInt(text.slice(index * 2, index * 2 + 2), 16);
    }

    return bytes;
}

function byteLength(modulus) {
    return Math.ceil(modulus.toString(16).length / 2);
}

function hex(bytes) {
    return Array.from(new Uint8Array(bytes), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

function randomBytes(length) {
    return crypto.getRandomValues(new Uint8Array(length));
}

function nonZeroBytes(length) {
    const bytes = randomBytes(length);

    for (let index = 0; index < length; index += 1) {
        while (0 === bytes[index]) {
            bytes[index] = randomBytes(1)[0];
        }
    }

    return bytes;
}

/** The bytes of an OCTET STRING, joined when it came in constructed pieces. */
function octets(octetString) {
    if (true === octetString.idBlock.isConstructed) {
        const pieces = octetString.valueBlock.value.map((piece) => new Uint8Array(piece.valueBlock.valueHexView));
        const joined = new Uint8Array(pieces.reduce((sum, piece) => sum + piece.length, 0));
        let offset = 0;

        for (const piece of pieces) {
            joined.set(piece, offset);
            offset += piece.length;
        }

        return joined;
    }

    return new Uint8Array(octetString.valueBlock.valueHexView);
}

/** A fresh ArrayBuffer of exactly these bytes, which is what PKI.js and WebCrypto take. */
function toBuffer(bytes) {
    const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);

    return view.buffer.slice(view.byteOffset, view.byteOffset + view.byteLength);
}

function addressesOf(certificate) {
    const addresses = [];
    const alternative = (certificate.extensions ?? []).find((extension) => OID.subjectAltName === extension.extnID);

    for (const name of alternative?.parsedValue?.altNames ?? []) {
        if (1 === name.type && "string" === typeof name.value) {
            addresses.push(name.value.trim().toLowerCase());
        }
    }

    for (const attribute of certificate.subject.typesAndValues) {
        if (OID.emailAddress === attribute.type) {
            addresses.push(String(attribute.value.valueBlock.value).trim().toLowerCase());
        }
    }

    return [...new Set(addresses.filter((address) => address.includes("@")))];
}

function nameOf(name) {
    for (const type of [OID.commonName, "2.5.4.10", OID.emailAddress]) {
        const attribute = name.typesAndValues.find((candidate) => type === candidate.type);

        if (undefined !== attribute) {
            return String(attribute.value.valueBlock.value);
        }
    }

    return "";
}
