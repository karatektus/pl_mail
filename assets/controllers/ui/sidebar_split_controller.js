/*
 * ui--split, under a third name, for the boundary between the sidebar and the
 * pane beside it (#699).
 *
 * The same class, modeless, with `side-value="start"` — the sidebar is the one
 * pane to the LEFT of its handle — and `inert-when` for the icon rail. See "A
 * boundary on the OTHER side" in split_controller.js and
 * _partials/_sidebar_pane.html.twig.
 *
 * Registered again rather than nested as a second `ui--split` for the reason
 * side_split_controller.js gives: on the mailbox the sidebar sits INSIDE the
 * calendar's ui--split, whose pane, main and handle targets, stylesheet rules
 * and specs all mean "the calendar's boundary". A second element answering to
 * that name made every one of them ambiguous.
 */
export { default } from "./split_controller.js";
