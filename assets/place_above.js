/**
 * Open a panel over the control that asked for it, in the top layer.
 *
 * The compose window's popovers used to be pinned to the right edge of the
 * toolbar block, because a button's x depended on where the row wrapped. The
 * row no longer wraps (compose--action-overflow), so that excuse is gone, and
 * a panel that opens at the far right of the window, away from the link button
 * or the ⋮ that was pressed, reads as something unrelated arriving. ui--dropdown
 * moved its menus over their button first (#519); this is that code, lifted out
 * so the link popover and the plain-text and send warnings — which are not
 * dropdowns — open the same way instead of keeping a second copy.
 *
 * The panel goes into the browser's top layer (`popover="manual"`): nothing
 * clips it, and the compose window's backdrop blur cannot make
 * `position: fixed` relative to the window. `manual` and not `auto`: closing
 * stays the caller's (outside click, Escape), and auto's light dismiss would
 * eat the first click inside the panel.
 *
 * Above the anchor by default, because these controls sit at the bottom of the
 * window. Below it when there is not room above and there is more below — the
 * link popover also opens over a link in the BODY, which can be the first line
 * under the window's title bar.
 *
 * `anchor` is a function, asked again on every placement, so a caller can fall
 * back to ⋮ when its button has been folded into that menu.
 *
 * Returns the function that takes the panel out of the top layer again. It
 * does not hide the panel; `hidden` stays the caller's. Its `place` property
 * places the panel again, for when what is in it changed size.
 */
export function liftAbove(panel, anchor) {
    if ("function" !== typeof panel.showPopover) {
        return () => {};
    }

    if (false === panel.hasAttribute("popover")) {
        panel.setAttribute("popover", "manual");
    }

    if (false === panel.matches(":popover-open")) {
        panel.showPopover();
    }

    const place = () => placeNear(panel, anchor());

    place();

    // Capture: the scroller that moved is the compose body or the page, and
    // scroll does not bubble.
    window.addEventListener("resize", place);
    window.addEventListener("scroll", place, { capture: true, passive: true });

    const drop = () => {
        window.removeEventListener("resize", place);
        window.removeEventListener("scroll", place, { capture: true });

        if (panel.matches(":popover-open")) {
            panel.hidePopover();
        }

        for (const property of ["position", "margin", "inset", "width", "max-width", "max-height", "left", "top", "bottom"]) {
            panel.style.removeProperty(property);
        }
    };

    // For a panel whose contents changed size while it was up.
    drop.place = place;

    return drop;
}

/** The element if it is laid out, null if it is hidden (folded into ⋮). */
export function visible(element) {
    return element instanceof Element && element.getClientRects().length > 0 ? element : null;
}

function placeNear(panel, anchor) {
    if (false === panel.matches(":popover-open") || null === anchor) {
        return;
    }

    const margin = 8;
    const box = anchor.getBoundingClientRect();
    const style = panel.style;

    style.position = "fixed";
    style.margin = "0";
    style.inset = "auto";
    // Below md the window is the whole screen and the panel its width, as the
    // templates' left-2 right-2 had it.
    style.width = window.innerWidth < 768 ? `${window.innerWidth - margin * 2}px` : "";
    style.maxWidth = `${window.innerWidth - margin * 2}px`;
    style.maxHeight = "";

    const natural = panel.getBoundingClientRect();
    const roomAbove = box.top - margin * 2;
    const roomBelow = window.innerHeight - box.bottom - margin * 2;
    const below = natural.height > roomAbove && roomBelow > roomAbove;

    const left = Math.min(Math.max(margin, box.left), Math.max(margin, window.innerWidth - natural.width - margin));

    style.left = `${Math.round(left)}px`;
    style.maxHeight = `${Math.max(160, Math.floor(below ? roomBelow : roomAbove))}px`;

    if (below) {
        style.bottom = "auto";
        style.top = `${Math.round(box.bottom + margin)}px`;
    } else {
        style.top = "auto";
        style.bottom = `${Math.round(window.innerHeight - box.top + margin)}px`;
    }
}
