/**
 * "Pressed somewhere else": what closes a menu that is open (#807).
 *
 * A document `click` listener was the whole of it, and it missed two presses
 * that both happen in front of an open menu in the reading pane:
 *
 *  - A tap on iOS. Safari only fires `click` for an element it thinks is
 *    clickable — a link, a button, anything with a click handler or
 *    `cursor: pointer`. A tap on the empty part of the pane, a heading or a
 *    paragraph fires none, so the menu stayed open however often it was
 *    tapped away from. `pointerdown` fires for every touch.
 *  - A press inside the message. The body is a sandboxed frame, a document of
 *    its own, and no event in it reaches this one. Most of what is "outside"
 *    the ⋮ menu IS the message. The frame's own script reports the press (see
 *    templates/mail/_frame_script.js), and mail--message-frame re-announces it
 *    here as `mail:frame-pointerdown`.
 *
 * `click` stays as well: a button pressed from the keyboard is a click with no
 * pointer before it.
 *
 * All in the capture phase, so a press is seen even by way of something that
 * stops propagation on the way up.
 *
 * @param {Element}  element the menu and its button: a press inside it is not "outside"
 * @param {Function} close   called once per outside press
 *
 * @returns {Function} removes the listeners
 */
export function onOutsidePress(element, close) {
    const handle = (event) => {
        // The frame's press has the document as its target; it is outside by
        // definition, whatever the menu contains.
        if ("mail:frame-pointerdown" === event.type || false === element.contains(event.target)) {
            close();
        }
    };

    document.addEventListener("pointerdown", handle, { capture: true });
    document.addEventListener("click", handle, { capture: true });
    document.addEventListener("mail:frame-pointerdown", handle);

    return () => {
        document.removeEventListener("pointerdown", handle, { capture: true });
        document.removeEventListener("click", handle, { capture: true });
        document.removeEventListener("mail:frame-pointerdown", handle);
    };
}
