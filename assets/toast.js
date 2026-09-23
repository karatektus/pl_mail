/**
 * Raise a toast from a script.
 *
 * Most toasts arrive from the server in a turbo-stream. The ones a script has
 * to raise — a request that failed, a sync that finished — are cloned from the
 * `<template id="toast-template-…">` the layout renders from the SAME partial,
 * so they are the same pane, the same theme tokens, the same entrance and the
 * same timed exit. Two controllers used to build theirs by hand in the old
 * filled colours, which no theme could reach.
 *
 * `reference` is the request id an error can be found by in the admin log
 * browser (see request_errors.js): `{ id, label, copyLabel }`, where `label`
 * is the translated "Ref. %id%". It is shown small after the message with a
 * copy button — the id alone is what goes on the clipboard, because that is
 * what the log browser's Reference field takes.
 */
export function showToast(message, { type = "info", reference = null } = {}) {
    const region = document.getElementById("toast-region");
    const template = document.getElementById(`toast-template-${type}`)
        ?? document.getElementById("toast-template-info");

    if (null === region || null === template) {
        return null;
    }

    const toast = template.content.firstElementChild.cloneNode(true);
    const text = toast.querySelector("[data-toast-message]");

    if (null !== text) {
        text.textContent = message;

        if (null !== reference && reference.id) {
            text.append(referenceChip(reference, template));
        }
    }

    // An error is something to read, not a passing note.
    if ("error" === type) {
        toast.setAttribute("role", "alert");
    }

    region.append(toast);

    return toast;
}

/**
 * "Ref. 2a4109f7" and a copy button, through ui--clipboard so it confirms the
 * way every other copy button in the app does. The words come from the
 * template's data attributes — see the layout.
 */
function referenceChip({ id, label = "%id%", copyLabel = "" }, template) {
    const chip = document.createElement("span");
    chip.className = "ml-2 inline-flex items-baseline gap-1 text-xs font-normal text-ink-faint tabular-nums";
    // setAttribute, not dataset: a Stimulus identifier's "--" has no camelCase
    // spelling, and dataset.uiClipboardTarget writes data-ui-clipboard-target,
    // which Stimulus never reads.
    chip.setAttribute("data-controller", "ui--clipboard");
    chip.setAttribute("data-ui--clipboard-confirm-text-value", template.dataset.clipboardCopied ?? "");
    chip.setAttribute("data-ui--clipboard-failed-text-value", template.dataset.clipboardFailed ?? "");

    const [before, after = ""] = label.split("%id%");
    const source = document.createElement("span");
    source.setAttribute("data-ui--clipboard-target", "source");
    source.className = "select-all";
    source.textContent = id;
    chip.append(before, source, after);

    const button = document.createElement("button");
    button.type = "button";
    button.setAttribute("data-action", "click->ui--clipboard#copy");
    button.setAttribute("aria-label", copyLabel);
    button.title = copyLabel;
    button.className = "self-center inline-flex items-center justify-center w-6 h-6 -my-1 rounded "
        + "text-ink-faint hover:text-ink hover:bg-hover transition-colors cursor-pointer";

    const icon = document.createElement("i");
    icon.className = "fa-regular fa-copy text-[11px]";
    icon.setAttribute("data-ui--clipboard-target", "icon");
    icon.setAttribute("aria-hidden", "true");
    button.append(icon);

    chip.append(button);

    return chip;
}
