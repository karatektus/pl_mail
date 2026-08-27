/**
 * Reading a stream of NDJSON frames from a fetch() body.
 *
 * One JSON object per line, which is the least framing that survives a chunk
 * boundary landing mid-object. A chunk off the wire is NOT a frame: it can
 * carry half an object, or three of them, and the tail has to be held back
 * until its newline arrives. The server does exactly this to Ollama's own
 * stream one hop upstream — the failure mode for splitting on chunk boundaries
 * instead is a parser that works perfectly against localhost and silently drops
 * words over a real network.
 *
 * ITS OWN MODULE BECAUSE THE SECOND READER ARRIVED
 * ────────────────────────────────────────────────
 * compose/ai_assist_controller grew this first and mail/thread_summary_controller
 * needs it byte for byte: two features, two panes, one wire format, and no part
 * of the buffering above is specific to either. This is the moment csrf.js
 * describes — "all nine were still byte-identical, which is the moment to
 * collapse them rather than the moment after."
 *
 * WHAT IS DELIBERATELY NOT HERE
 * ─────────────────────────────
 * The state machine. What a `state`, `token`, `done` or `error` frame MEANS is
 * entirely different in a composer and in a reading pane — one inserts text at
 * a caret and offers accept/discard, the other renders a card and stores
 * nothing — so the two controllers keep their own #apply(). What they share is
 * the part that is genuinely one thing: getting whole JSON objects out of a
 * byte stream.
 */

/**
 * Feed whole frames to a callback until the body ends.
 *
 * Returns nothing. Whether the stream ENDING is a success or a dropped
 * connection is the caller's to decide, because only the caller knows whether a
 * terminal frame arrived — see both controllers, which check their own abort
 * controller afterwards.
 *
 * @param {ReadableStream<Uint8Array>} body     `response.body`
 * @param {(frame: object) => void}    onFrame  called once per parsed line
 * @param {() => boolean}              isCurrent asked after every read; false
 *        abandons the stream. An aborted fetch settles asynchronously, so
 *        without this the PREVIOUS run's frames keep arriving into a surface
 *        the new run has already claimed.
 * @returns {Promise<void>}
 */
export async function readFrames(body, onFrame, isCurrent) {
    const reader = body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";

    for (;;) {
        const { value, done } = await reader.read();

        if (false === isCurrent()) return;

        if (true === done) break;

        // `stream: true` so a multi-byte character split across two chunks is
        // held rather than decoded into a replacement character. Every umlaut
        // in a German draft sits on that boundary eventually, and so does every
        // one in a German summary.
        buffer += decoder.decode(value, { stream: true });

        let cut;

        while (-1 !== (cut = buffer.indexOf("\n"))) {
            const line = buffer.slice(0, cut).trim();
            buffer = buffer.slice(cut + 1);

            if ("" === line) continue;

            let frame;

            try {
                frame = JSON.parse(line);
            } catch {
                // A truncated final line from a connection that died mid-frame.
                // Skipped rather than fatal: the frames before it are still the
                // text somebody is watching arrive.
                continue;
            }

            onFrame(frame);
        }
    }
}
