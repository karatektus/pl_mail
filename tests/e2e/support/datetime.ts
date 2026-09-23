import type { Locator } from "@playwright/test";

/**
 * Set a field calendar--when's picker draws over — the event editor's hidden
 * `#event-starts` / `#event-ends`, the scheduler's custom time — as though the
 * picker had.
 *
 * For specs about what the FORM does with a time (the server's refusal, the
 * end following the start, a series saved, a send refused as too soon), not
 * about the picker: its own spec clicks through it. Written through the input's value
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
