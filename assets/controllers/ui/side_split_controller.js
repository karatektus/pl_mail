/*
 * ui--split, under a second name, for a docked pane that is not the calendar.
 *
 * The same class: the drag, the clamp, the arrow keys, the double-click reset
 * and the coalesced persist are exactly ui--split's, with its modes switched
 * off (modes-value="false"). Registered again rather than nested as a second
 * `ui--split` because that name means "the calendar's boundary" to more than
 * this file: the stylesheet's calendar positions, and every spec that finds
 * the shell or its handle by it. A second element answering to it made all of
 * those ambiguous. See _contacts_pane.html.twig.
 */
export { default } from "./split_controller.js";
