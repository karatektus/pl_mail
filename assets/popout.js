/**
 * Getting a menu out from under the pane it was declared in.
 *
 * ## The trap
 *
 * plMail's panes carry `backdrop-filter`, and that has two consequences most
 * CSS does not prepare you for. It makes the pane a **stacking context**, so a
 * `z-30` menu inside it can never rise above a `z-20` header OUTSIDE it —
 * z-index only compares within a context, and raising the number does nothing.
 * And it makes the pane a **containing block for `position: fixed`**, so the
 * usual escape hatch is not one: a fixed menu anchors to the pane and is
 * clipped by it, exactly like an absolute one.
 *
 * On top of that every ancestor from the pane down is `overflow: hidden`, so
 * the menu is clipped as well as painted underneath.
 *
 * That is why the label menu disappeared behind the navbar, and why the compose
 * recipient dropdown could not overlay the dialog and shoved it down the page
 * instead. Same cause, two symptoms, and neither is fixable with a z-index.
 *
 * tooltip_controller.js hit this first and solved it by rendering its bubble on
 * <body>. That works and it costs something these menus cannot pay: moving an
 * element out of its subtree takes it away from the Stimulus controller whose
 * targets and actions are scoped to it.
 *
 * ## The top layer
 *
 * The popover API puts an element in the browser's top layer — above every
 * stacking context, clipped by nothing — while leaving it exactly where it is
 * in the DOM. Targets keep resolving, actions keep firing, the controller does
 * not know anything moved. It is the same property `<dialog>.showModal()` gives
 * the confirmation dialog, without the modality.
 *
 * Position has to be supplied, because a top-layer element is positioned
 * against the viewport rather than against the trigger it belongs to. That is
 * the whole of what this module does.
 */

/** Gap between trigger and panel, matching the `mt-1` these menus used. */
const OFFSET = 4;

/** Keep this much clear of the viewport edge. */
const MARGIN = 8;

/**
 * Show `panel` in the top layer, pinned under `trigger`.
 *
 * Falls back to leaving the panel where it is when the browser has no popover
 * support. That is not a hypothetical branch worth dropping: without it the
 * menu would be `display: none` forever on an older browser, which is worse
 * than the clipping this exists to fix.
 *
 * @param {HTMLElement} trigger
 * @param {HTMLElement} panel
 */
export function pinToTopLayer(trigger, panel) {
    if (typeof panel.showPopover !== "function") {
        return;
    }

    if (false === panel.hasAttribute("popover")) {
        // "manual" rather than "auto": light-dismiss would fight the outside
        // click handling these controllers already do, and auto popovers close
        // each other — so opening the label menu would shut a menu that is
        // meant to stay open beneath it.
        panel.setAttribute("popover", "manual");
    }

    panel.showPopover();
    position(trigger, panel);

    // The trigger moves when the list behind it scrolls, and the viewport
    // changes under rotation. Both leave a top-layer panel floating where the
    // trigger used to be, because nothing about it is anchored any more.
    const reposition = () => position(trigger, panel);

    panel.__plReposition = reposition;
    window.addEventListener("scroll", reposition, { capture: true, passive: true });
    window.addEventListener("resize", reposition, { passive: true });
}

/**
 * Take it back out of the top layer.
 *
 * @param {HTMLElement} panel
 */
export function releaseFromTopLayer(panel) {
    if (panel.__plReposition) {
        window.removeEventListener("scroll", panel.__plReposition, { capture: true });
        window.removeEventListener("resize", panel.__plReposition);
        delete panel.__plReposition;
    }

    if (typeof panel.hidePopover === "function" && true === panel.matches(":popover-open")) {
        panel.hidePopover();
    }
}

/**
 * Under the trigger, right edges aligned, nudged back on screen if that would
 * put it off the edge.
 *
 * Right-aligned because that is how every one of these menus was already
 * anchored (`absolute right-0`), and flipping above the trigger when there is
 * no room below is what stops a long label list from running off the bottom of
 * a short window.
 */
function position(trigger, panel) {
    const anchor = trigger.getBoundingClientRect();
    const box    = panel.getBoundingClientRect();

    let top = anchor.bottom + OFFSET;

    if (top + box.height > window.innerHeight - MARGIN) {
        const above = anchor.top - box.height - OFFSET;

        // Only flip when there is genuinely more room up there. On a very short
        // window both are bad and below is the one that reads as a menu.
        top = above >= MARGIN ? above : Math.max(MARGIN, window.innerHeight - box.height - MARGIN);
    }

    let left = anchor.right - box.width;

    left = Math.min(left, window.innerWidth - box.width - MARGIN);
    left = Math.max(MARGIN, left);

    panel.style.position = "fixed";
    panel.style.margin   = "0";
    panel.style.top      = `${Math.round(top)}px`;
    panel.style.left     = `${Math.round(left)}px`;
}
