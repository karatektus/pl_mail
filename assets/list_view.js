/**
 * The list the page is showing, as the server names it: the frame's scope
 * (`inbox`, `label`, `all_mail`, …) and the one thing that varies within it
 * (the category tab, the label id, the account id).
 *
 * Sent along with a row's own buttons — archive, snooze, star — so that a
 * reply can say whether the row is still in this list. Today only All Mail
 * asks (see App\Service\Mail\AllMailView): archived or snoozed, a conversation
 * is still in it, where every other list loses it. The toolbar sends the same
 * pair from its own values; see list_toolbar_controller.
 *
 * Read off the live frame at the moment of the click rather than kept, because
 * the frame is replaced on every navigation and mail-pane copies the new
 * list's values onto it (_copyListContext).
 *
 * @returns {{scope: string, value: string}}
 */
export function listView() {
    const frame = document.getElementById("inbox-list-frame");

    return {
        scope: frame?.dataset.syncScope ?? "",
        value: frame?.dataset.listScopeValue ?? "",
    };
}
