/*
 * history_layer.js — Back closes something on the page instead of leaving it.
 *
 * On a phone, Back is how anything that has been opened is put away. For a
 * layer that is not a page of its own — the zoomed screenshot in What's new is
 * the first (#179) — that means a history entry of its own: openLayer() pushes
 * one, and Back pops it and calls the layer's onBack instead of going anywhere.
 *
 * ## Why this file is imported before everything else in app.js
 *
 * The popstate that Back fires must reach nobody but the layer. Turbo answers
 * any popstate onto an entry carrying its state with a restoration visit — the
 * whole page drawn again, What's new and all — and the mail pane treats one
 * without its own state as "back to the list". Both listen on window, and on
 * window listeners run in the order they were added, capture or not (tried in
 * Chromium: a capture listener added later still runs after the others), so
 * stopImmediatePropagation() only keeps them out if this listener came first.
 * Being the first module app.js evaluates is what makes it first: Turbo is
 * started from stimulus_bootstrap.js, which comes after.
 *
 * ## Closing without Back
 *
 * The X, Escape, or the dialog around the layer closing: closeLayer() goes
 * back through the entry so the stack is as it was found, and swallows the
 * popstate that follows, for the same reason as above.
 *
 * One layer at a time. Nothing stacks today, and a stack would need to know
 * which entry belongs to which layer when they are closed out of order.
 */

let onBack = null
let swallow = 0

window.addEventListener("popstate", (event) => {
    if (swallow > 0) {
        swallow--
        event.stopImmediatePropagation()

        return
    }

    if (null !== onBack) {
        const back = onBack

        onBack = null
        event.stopImmediatePropagation()
        back()
    }
})

/**
 * @param {() => void} back called when Back is pressed while the layer is open
 */
export function openLayer(back) {
    closeLayer()

    history.pushState({ ...history.state, historyLayer: true }, "")
    onBack = back
}

/**
 * Takes the layer's entry back off the stack, if it is still the current one —
 * after a Turbo visit it is not, and going back then would be a navigation.
 */
export function closeLayer() {
    onBack = null

    if (true === history.state?.historyLayer) {
        swallow++
        history.back()
    }
}
