/**
 * pdf.js as the BROWSER sees it.
 *
 * It is vendored under public/ rather than declared in the importmap — it needs
 * stable directory prefixes and AssetMapper digests every filename it compiles
 * — so specs that load it inside page.evaluate() import it by its served path.
 * TypeScript has no way to resolve that, and this is the declaration that says
 * so deliberately instead of leaving a suppression comment at the call site.
 *
 * Typed loosely on purpose: the specs use a handful of pdf.js entry points and
 * mirroring the library's real surface here would be a second copy of it to
 * keep in step with an upgrade.
 */
declare module "/pdfjs/*" {
    const pdfjs: {
        getDocument(options: Record<string, unknown>): {
            promise: Promise<{
                numPages: number;
                getPage(number: number): Promise<{
                    rotate: number;
                    getViewport(options: { scale: number }): { width: number; height: number };
                    render(options: Record<string, unknown>): { promise: Promise<void> };
                }>;
            }>;
            destroy(): Promise<void>;
        };
        GlobalWorkerOptions: { workerSrc: string; workerPort: Worker | null };
    };

    export = pdfjs;
}

/**
 * openpgp.js as the BROWSER sees it: an importmap entry, so a spec that needs
 * a real OpenPGP key — encryption.spec.ts, importing one the way a person
 * would — makes it inside page.evaluate() with the page's own copy, and Node
 * never resolves the name. Typed as loosely as pdf.js above, for its reason.
 */
declare module "openpgp" {
    export function generateKey(options: Record<string, unknown>): Promise<{ privateKey: string; publicKey: string }>;
    export function readKey(options: { armoredKey: string } | { binaryKey: Uint8Array }): Promise<{ write(): Uint8Array; getFingerprint(): string }>;
    export function readPrivateKey(options: { armoredKey: string }): Promise<unknown>;
    export function createMessage(options: { text: string } | { binary: Uint8Array }): Promise<unknown>;
    export function encrypt(options: Record<string, unknown>): Promise<string>;
    export function sign(options: Record<string, unknown>): Promise<string>;
}
