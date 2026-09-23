import type { Locator } from "@playwright/test";

/**
 * Drive a field that ui--datetime has taken over, the way a person does.
 *
 * The native `datetime-local` / `time` input is still in the page — hidden,
 * named, carrying the ISO value the form posts — but nobody can type into it;
 * the date field and the time text field drawn after it are what is on screen.
 * So a spec hands over the hidden input it always had a locator for, and this
 * fills the visible pair beside it. The time is typed as "HH:MM", which the
 * field reads on either clock setting.
 *
 * Assertions on the hidden input itself — its value, aria-invalid — stay as
 * they were: that is the contract the rest of the page reads.
 */
export function visibleField(field: Locator): Locator {
    return field.locator("xpath=following-sibling::*[@data-datetime-field][1]");
}

export async function fillDateTime(field: Locator, value: string): Promise<void> {
    const box = visibleField(field);
    const [date, time] = value.includes("T") ? value.split("T") : [null, value];

    if (null !== date) {
        await box.locator('input[type="date"]').fill(date);
    }

    const timeField = box.locator('input[type="text"]');
    await timeField.fill(time);
    await timeField.press("Tab");
}

/**
 * Set the event editor's start or end — the hidden `#event-starts` /
 * `#event-ends` under calendar--when's picker — as though the picker had.
 *
 * For specs about what the FORM does with a time (the server's refusal, the
 * end following the start, a series saved from the editor), not about the
 * picker: its own spec clicks through it. Written through the input's value
 * setter, which the picker has wrapped to redraw from, with the `input` and
 * `change` a real pick fires — calendar--event-form listens for the second.
 */
export async function setWhen(field: Locator, value: string): Promise<void> {
    await field.evaluate((el, v) => {
        (el as HTMLInputElement).value = v;
        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.dispatchEvent(new Event("change", { bubbles: true }));
    }, value);
}
