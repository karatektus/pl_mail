/*
 * Where the current Turbo visit left from.
 *
 * document.referrer is frozen at the initial page load — Turbo Drive visits
 * never update it — so anything that wants to know "did the user just come
 * here from the calendar page?" cannot ask the browser. This records the
 * departure point at the moment of departure instead, and the reader
 * (ui/split_controller's arrival demotion) consumes it read-once.
 *
 * sessionStorage, same as the mode handoff: per-tab, survives the visit,
 * gone with the tab.
 */
export const NAV_ORIGIN_KEY = "plmail.nav-origin";

document.addEventListener("turbo:before-visit", () => {
    try {
        window.sessionStorage.setItem(NAV_ORIGIN_KEY, window.location.pathname);
    } catch {
        // Storage denied — the reader falls back to document.referrer, which
        // is at least correct for full page loads.
    }
});
