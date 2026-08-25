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
